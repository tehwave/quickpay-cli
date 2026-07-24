<?php

use App\Commands\PaymentsGetCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=get-secret');
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('gets a payment with operations size and preserves original json', function () {
    $raw = '{"id":42,"order_id":"order-42","custom_field":"same"}';
    Http::fake(['https://api.quickpay.net/payments/42*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);

    $this->artisan('payments:get', ['id' => '42', '--operations-size' => '0', '--json' => true])
        ->expectsOutput($raw)
        ->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/payments/42'
            && $query === ['operations_size' => '0'];
    });
});

it('renders payment details and an operations table', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'id' => 42,
        'order_id' => 'order-42',
        'currency' => 'DKK',
        'state' => 'processed',
        'accepted' => true,
        'balance' => 1000,
        'test_mode' => false,
        'created_at' => '2026-07-24T09:00:00Z',
        'updated_at' => '2026-07-24T10:00:00Z',
        'operations' => [[
            'id' => 9,
            'type' => 'authorize',
            'amount' => 1000,
            'pending' => false,
            'qp_status_code' => '20000',
            'qp_status_msg' => 'Approved',
            'aq_status_code' => '000',
            'aq_status_msg' => 'OK',
            'created_at' => '2026-07-24T09:01:00Z',
        ]],
    ])]);
    $command = new PaymentsGetCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['id' => '42']))->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('order-42')
        ->toContain('processed')
        ->toContain('Operations')
        ->toContain('authorize')
        ->toContain('20000 Approved')
        ->toContain('000 OK');
});

it('validates payment id and operations size before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('payments:get', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    [['id' => '0'], 'id'],
    [['id' => '-1'], 'id'],
    [['id' => 'one'], 'id'],
    [['id' => '999999999999999999999999999999'], 'id'],
    [['id' => '1', '--operations-size' => '-1'], 'operations-size'],
    [['id' => '1', '--operations-size' => '1.5'], 'operations-size'],
    [['id' => '1', '--operations-size' => '999999999999999999999999999999'], 'operations-size'],
]);
