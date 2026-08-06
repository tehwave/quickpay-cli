<?php

use App\Commands\CallbacksReplayCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    $this->originalPrivateKey = getenv('QUICKPAY_PRIVATE_KEY');
    putenv('QUICKPAY_API_KEY=replay-api-secret');
    putenv('QUICKPAY_PRIVATE_KEY=replay-private-key');
});

afterEach(function () {
    foreach ([
        'QUICKPAY_API_KEY' => $this->originalApiKey,
        'QUICKPAY_PRIVATE_KEY' => $this->originalPrivateKey,
    ] as $name => $value) {
        $value === false ? putenv($name) : putenv("{$name}={$value}");
    }
});

it('replays a signed current payment callback and emits only a safe json summary', function () {
    $raw = '{"id":42,"order_id":"order-42","merchant_id":123,"operations":[]}';
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response($raw),
        'http://localhost:8000/hooks/quickpay' => Http::response('local-secret-body', 204),
    ]);

    $command = new CallbacksReplayCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute([
        'payment-id' => '42',
        '--to' => 'http://localhost:8000/hooks/quickpay',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    $output = $tester->getDisplay();
    expect($status)->toBe(0)
        ->and($tester->getErrorOutput())->toBe('')
        ->and(json_decode($output, true))->toBe([
            'payment_id' => '42',
            'order_id' => 'order-42',
            'destination' => 'http://localhost:8000/hooks/quickpay',
            'status' => 204,
            'success' => true,
        ])
        ->and($output)->not->toContain($raw, 'local-secret-body', 'replay-private-key', 'replay-api-secret');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'http://localhost:8000/hooks/quickpay') {
            return false;
        }

        return $request->body() === '{"id":42,"order_id":"order-42","merchant_id":123,"operations":[]}'
            && $request->header('QuickPay-Checksum-Sha256') === [
                hash_hmac('sha256', $request->body(), 'replay-private-key'),
            ];
    });
});

it('resolves an order id and fetches the private key from Quickpay when no environment override exists', function () {
    putenv('QUICKPAY_PRIVATE_KEY');
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([['id' => 42, 'order_id' => 'order-42']]),
        'https://api.quickpay.net/payments/42' => Http::response([
            'id' => 42,
            'order_id' => 'order-42',
            'merchant_id' => 123,
            'operations' => [],
        ]),
        'https://api.quickpay.net/account/private-key' => Http::response(['private_key' => 'remote-private-key']),
        'https://merchant.example/callback' => Http::response('', 303),
    ]);

    $command = new CallbacksReplayCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute([
        '--order-id' => 'order-42',
        '--to' => 'https://merchant.example/callback',
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('Delivered callback for payment 42', 'HTTP 303');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/account/private-key');
    Http::assertSentCount(4);
});

it('requires exactly one selector and an explicit valid destination before making requests', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('callbacks:replay', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'no selector' => [['--to' => 'http://localhost/callback'], 'payment ID or --order-id'],
    'both selectors' => [['payment-id' => '42', '--order-id' => 'order-42', '--to' => 'http://localhost/callback'], 'exactly one'],
    'no destination' => [['payment-id' => '42'], '--to'],
    'invalid destination' => [['payment-id' => '42', '--to' => 'file:///tmp/callback'], 'valid HTTP or HTTPS URL'],
]);

it('returns a nonzero result with a json summary when local delivery is rejected', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response([
            'id' => 42,
            'order_id' => 'order-42',
            'merchant_id' => 123,
        ]),
        'http://localhost/callback' => Http::response('do not print me', 500),
    ]);

    $command = new CallbacksReplayCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute([
        'payment-id' => '42',
        '--to' => 'http://localhost/callback',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and(json_decode($tester->getDisplay(), true))->toMatchArray([
            'status' => 500,
            'success' => false,
        ])
        ->and($tester->getDisplay())->not->toContain('do not print me');
});

it('redacts a credential that collides with human summary fields', function () {
    putenv('QUICKPAY_API_KEY=42');
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response([
            'id' => 42,
            'order_id' => 'safe-order',
            'merchant_id' => 123,
        ]),
        'http://localhost/callback' => Http::response('', 200),
    ]);
    $command = new CallbacksReplayCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'payment-id' => '42',
        '--to' => 'http://localhost/callback',
    ]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->not->toContain('payment 42');
});
