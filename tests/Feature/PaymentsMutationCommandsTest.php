<?php

use App\Commands\PaymentsCaptureCommand;
use App\Commands\PaymentsRefundCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=mutation-secret');
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('fetches summarizes confirms and captures with the documented request shape', function () {
    $raw = '{"id":42,"state":"processed","custom":"unchanged"}';
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/capture*' => Http::response($raw, 200, ['Content-Type' => 'application/json']),
    ]);
    $command = new PaymentsCaptureCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '1250',
        '--synchronized' => true,
        '--callback-url' => 'https://merchant.test/callback',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toBe($raw)
        ->and($tester->getErrorOutput())
        ->toContain('Payment ID: 42')
        ->toContain('Order ID: order-42')
        ->toContain('Currency: DKK')
        ->toContain('State: authorized')
        ->toContain('Accepted: yes')
        ->toContain('Balance: 2000')
        ->toContain('Operation: capture')
        ->toContain('Amount: 1250')
        ->toContain('Continue?');

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)
        ->and($requests[0][0]->method())->toBe('GET')
        ->and($requests[1][0]->method())->toBe('POST');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/payments/42/capture'
            && array_key_exists('synchronized', $query)
            && $request->header('QuickPay-Callback-Url') === ['https://merchant.test/callback']
            && $request->data() === ['amount' => 1250];
    });
});

it('does not mutate when payment confirmation is declined', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(paymentFixture())]);

    $this->artisan('payments:capture', ['id' => '42', 'amount' => '1250'])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Cancelled')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});

it('requires yes for non-interactive payment mutations after showing the fetched context', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(paymentFixture())]);
    $command = new PaymentsCaptureCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '1250',
        '--json' => true,
    ], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())
        ->toContain('Payment ID: 42')
        ->toContain('--yes');
    Http::assertSentCount(1);
});

it('validates payment mutation inputs before fetching the payment', function (string $command, array $arguments, string $message) {
    Http::fake();

    $this->artisan($command, $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    ['payments:capture', ['id' => '0', 'amount' => '100'], 'id'],
    ['payments:capture', ['id' => '42', 'amount' => '0'], 'amount'],
    ['payments:capture', ['id' => '42', 'amount' => '100', '--callback-url' => "https://merchant.test/\r\nInjected: yes"], 'callback-url'],
    ['payments:refund', ['id' => '42', 'amount' => '100', '--vat-rate' => '-0.1'], 'vat-rate'],
    ['payments:refund', ['id' => '42', 'amount' => '100', '--vat-rate' => 'not-a-number'], 'vat-rate'],
    ['payments:cancel', ['id' => '999999999999999999999999999999'], 'id'],
]);

it('refunds with a numeric vat rate and supports yes without prompting', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/refund*' => Http::response(['id' => 42, 'state' => 'processed']),
    ]);
    $command = new PaymentsRefundCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([
        'id' => '42',
        'amount' => '300',
        '--vat-rate' => '0.25',
        '--yes' => true,
    ], ['interactive' => false]))->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Operation: refund')
        ->toContain('processed');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/payments/42/refund'
        && $request->data() === ['amount' => 300, 'vat_rate' => 0.25]);
});

it('cancels without an amount body and supports synchronized and callback options', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/cancel*' => Http::response(['id' => 42, 'state' => 'cancelled']),
    ]);

    $this->artisan('payments:cancel', [
        'id' => '42',
        '--synchronized' => true,
        '--callback-url' => 'https://merchant.test/cancelled',
        '--yes' => true,
    ])->expectsOutputToContain('Operation: cancel')->assertExitCode(0);

    $request = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->first()[0];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/payments/42/cancel')
        ->and($query)->toHaveKey('synchronized')
        ->and($request->header('QuickPay-Callback-Url'))->toBe(['https://merchant.test/cancelled'])
        ->and($request->body())->toBe('');
});

it('does not mutate when fetching payment context fails', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(['message' => 'Missing payment'], 404)]);

    $this->artisan('payments:cancel', ['id' => '42', '--yes' => true])
        ->expectsOutputToContain('Missing payment')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});

it('renders mutation api errors on stderr without exposing the credential', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/refund' => Http::response([
            'message' => 'Rejected mutation-secret',
            'errors' => ['amount' => ['Too high']],
            'qp_status_code' => '40000',
        ], 422),
    ]);
    $command = new PaymentsRefundCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '300',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())
        ->toContain('Rejected [redacted]')
        ->toContain('amount: Too high')
        ->toContain('qp_status_code: 40000')
        ->not->toContain('mutation-secret');
});

/** @return array<string, mixed> */
function paymentFixture(): array
{
    return [
        'id' => 42,
        'order_id' => 'order-42',
        'currency' => 'DKK',
        'state' => 'authorized',
        'accepted' => true,
        'balance' => 2000,
    ];
}
