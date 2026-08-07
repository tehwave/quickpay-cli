<?php

use App\Commands\Payments\CancelPaymentCommand;
use App\Commands\Payments\CapturePaymentCommand;
use App\Commands\Payments\RefundPaymentCommand;
use App\Console\Terminal\StdinTerminalDetector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=mutation-secret');
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return true;
        }
    });
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
    $command = new CapturePaymentCommand;
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
    $command = new CapturePaymentCommand;
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

it('does not accept piped confirmation for a payment mutation when stdin is not a tty', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(paymentFixture())]);
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return false;
        }
    });
    $command = new CapturePaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '1250',
    ], ['interactive' => true, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toContain('Payment ID: 42')->not->toContain('Continue?')
        ->and($tester->getErrorOutput())->toContain('--yes');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
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
    $command = new RefundPaymentCommand;
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
    $command = new RefundPaymentCommand;
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

it('sanitizes terminal controls and credentials in mutation summaries and human results', function () {
    $token = base64_encode(':mutation-secret');
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response([
            ...paymentFixture(),
            'order_id' => "mutation-secret\e]0;summary\x07\nnext\r\nend",
            'state' => $token,
        ]),
        'https://api.quickpay.net/payments/42/refund' => Http::response([
            'id' => 42,
            'order_id' => "mutation-secret\e]0;result\x07",
            'state' => $token,
        ]),
    ]);
    $command = new RefundPaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '300',
        '--yes' => true,
    ]);
    $display = $tester->getDisplay();

    expect($status)->toBe(0)
        ->and($display)->toContain('[redacted]\\x1B]0;summary\\x07\\x0Anext\\x0D\\x0Aend')
        ->toContain('[redacted]\\x1B]0;result\\x07')
        ->not->toContain('mutation-secret')
        ->not->toContain($token)
        ->not->toContain("\e")
        ->not->toContain("\x07");
});

it('renders payment error summaries without terminal control bytes', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'message' => "Missing\e]0;owned\x07\rpayment",
    ], 404)]);
    $command = new RefundPaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '300',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);
    $error = $tester->getErrorOutput();

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($error)->toContain('Missing\\x1B]0;owned\\x07\\x0Dpayment')
        ->not->toContain("\e")
        ->not->toContain("\x07");
});

it('fails json mode safely when a successful payment mutation response is not valid json', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/capture' => Http::response('<html>captured</html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $command = new CapturePaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        'amount' => '1250',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('valid JSON')
        ->not->toContain('<html>');
});

it('rejects non-object successful mutation json before writing stdout', function (
    string $commandClass,
    string $operation,
    array $arguments,
    string $body,
) {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        "https://api.quickpay.net/payments/42/{$operation}" => Http::response($body),
    ]);
    $command = new $commandClass;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        ...$arguments,
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('invalid payment mutation response');
})->with([
    'capture scalar' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], '42'],
    'capture list' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], '[]'],
    'capture null' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], 'null'],
    'refund scalar' => [RefundPaymentCommand::class, 'refund', ['id' => '42', 'amount' => '300'], '42'],
    'refund list' => [RefundPaymentCommand::class, 'refund', ['id' => '42', 'amount' => '300'], '[]'],
    'refund null' => [RefundPaymentCommand::class, 'refund', ['id' => '42', 'amount' => '300'], 'null'],
    'cancel scalar' => [CancelPaymentCommand::class, 'cancel', ['id' => '42'], '42'],
    'cancel list' => [CancelPaymentCommand::class, 'cancel', ['id' => '42'], '[]'],
    'cancel null' => [CancelPaymentCommand::class, 'cancel', ['id' => '42'], 'null'],
]);

it('accepts an empty object as a successful mutation response in json mode', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(paymentFixture()),
        'https://api.quickpay.net/payments/42/cancel' => Http::response('{}'),
    ]);
    $command = new CancelPaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'id' => '42',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toBe('{}')
        ->and($tester->getErrorOutput())->toContain('Operation: cancel');
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
