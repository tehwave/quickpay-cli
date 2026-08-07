<?php

use App\Commands\Payments\CreatePaymentLinkCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=link-secret');
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('creates a payment link with integer amount named options and nested fields', function () {
    $raw = '{"url":"https://payment.quickpay.net/link/abc","custom":"unchanged"}';
    Http::fake(['https://api.quickpay.net/payments/42/link' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);

    $this->artisan('payments:link', [
        'id' => '42',
        'amount' => '1000',
        '--continue-url' => 'https://merchant.test/continue',
        '--cancel-url' => 'https://merchant.test/cancel',
        '--callback-url' => 'https://merchant.test/callback',
        '--language' => 'da',
        '--payment-methods' => 'creditcard,mobilepay',
        '--auto-capture' => true,
        '--field' => ['invoice_address[email]=a@example.com'],
        '--json' => true,
    ])->expectsOutput($raw)->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.quickpay.net/payments/42/link'
        && $request->data() === [
            'invoice_address' => ['email' => 'a@example.com'],
            'amount' => 1000,
            'continue_url' => 'https://merchant.test/continue',
            'cancel_url' => 'https://merchant.test/cancel',
            'callback_url' => 'https://merchant.test/callback',
            'language' => 'da',
            'payment_methods' => 'creditcard,mobilepay',
            'auto_capture' => true,
        ]);
});

it('does not let fields supply or override reserved named link values', function () {
    Http::fake(['https://api.quickpay.net/payments/42/link' => Http::response(['url' => 'https://payment.quickpay.net/link/abc'])]);

    $this->artisan('payments:link', [
        'id' => '42',
        'amount' => '1000',
        '--continue-url' => 'https://merchant.test/safe',
        '--field' => [
            'amount=1',
            'continue_url=https://evil.test',
            'language=evil',
            'auto_capture=yes',
            'description=allowed',
        ],
        '--json' => true,
    ])->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['amount'] === 1000
            && $data['continue_url'] === 'https://merchant.test/safe'
            && ! array_key_exists('language', $data)
            && ! array_key_exists('auto_capture', $data)
            && $data['description'] === 'allowed';
    });
});

it('prominently renders the returned payment url', function () {
    Http::fake(['https://api.quickpay.net/payments/42/link' => Http::response([
        'url' => 'https://payment.quickpay.net/link/abc',
    ])]);
    $command = new CreatePaymentLinkCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['id' => '42', 'amount' => '1000']))->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Payment link')
        ->toContain('https://payment.quickpay.net/link/abc');
});

it('sanitizes credentials and terminal controls in link json and human output', function () {
    $token = base64_encode(':link-secret');
    $url = "https://payment.quickpay.net/link/link-secret/{$token}\e]0;owned\x07";
    $raw = json_encode(['url' => $url], JSON_THROW_ON_ERROR);
    Http::fake(['https://api.quickpay.net/payments/42/link' => Http::response($raw)]);
    $jsonCommand = new CreatePaymentLinkCommand;
    $jsonCommand->setLaravel(app());
    $jsonTester = new CommandTester($jsonCommand);

    $jsonStatus = $jsonTester->execute(
        ['id' => '42', 'amount' => '1000', '--json' => true],
        ['capture_stderr_separately' => true],
    );
    $json = $jsonTester->getDisplay();

    expect($jsonStatus)->toBe(0)
        ->and(json_validate($json))->toBeTrue()
        ->and($json)->not->toContain('link-secret')->not->toContain($token);

    $humanCommand = new CreatePaymentLinkCommand;
    $humanCommand->setLaravel(app());
    $humanTester = new CommandTester($humanCommand);
    $humanStatus = $humanTester->execute(['id' => '42', 'amount' => '1000']);
    $display = $humanTester->getDisplay();

    expect($humanStatus)->toBe(0)
        ->and($display)->toContain('[redacted]')
        ->toContain('\\x1B]0;owned\\x07')
        ->not->toContain('link-secret')
        ->not->toContain($token)
        ->not->toContain("\e");
});

it('rejects invalid successful link bodies before writing json', function (string $body) {
    Http::fake(['https://api.quickpay.net/payments/42/link' => Http::response($body)]);
    $command = new CreatePaymentLinkCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute(
        ['id' => '42', 'amount' => '1000', '--json' => true],
        ['capture_stderr_separately' => true],
    );

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('payment link URL')
        ->not->toContain($body);
})->with([
    'invalid json' => '<html>not json</html>',
    'scalar json' => '42',
    'list json' => '[{"url":"https://payment.quickpay.net/link/abc"}]',
    'missing url' => '{}',
    'empty url' => '{"url":""}',
    'non-string url' => '{"url":42}',
]);

it('validates link id amount and fields before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('payments:link', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    [['id' => '0', 'amount' => '1000'], 'id'],
    [['id' => 'one', 'amount' => '1000'], 'id'],
    [['id' => '42', 'amount' => '0'], 'amount'],
    [['id' => '42', 'amount' => '10.5'], 'amount'],
    [['id' => '42', 'amount' => '999999999999999999999999999999'], 'amount'],
    [['id' => '42', 'amount' => '1000', '--field' => ['basket=value', 'basket[0]=x']], 'conflicts'],
]);
