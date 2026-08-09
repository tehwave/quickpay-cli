<?php

use App\Callbacks\Delivery\CallbackDelivery;
use App\Callbacks\Delivery\CallbackDeliveryFailure;
use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Watching\CallbackDeliveryException;
use App\Callbacks\Watching\CallbackWatcher;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Commands\Callbacks\WatchCallbacksCommand;
use App\Commands\Payments\CancelPaymentCommand;
use App\Commands\Payments\CapturePaymentCommand;
use App\Commands\Payments\CreatePaymentLinkCommand;
use App\Commands\Payments\RefundPaymentCommand;
use App\Quickpay\QuickpayClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    $this->originalPrivateKey = getenv('QUICKPAY_PRIVATE_KEY');
    putenv('QUICKPAY_API_KEY=watch-api-key');
    putenv('QUICKPAY_PRIVATE_KEY=watch-private-key');
});

afterEach(function () {
    foreach ([
        'QUICKPAY_API_KEY' => $this->originalApiKey,
        'QUICKPAY_PRIVATE_KEY' => $this->originalPrivateKey,
    ] as $name => $value) {
        $value === false ? putenv($name) : putenv("{$name}={$value}");
    }
});

it('validates watch selectors destination and polling interval before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('callbacks:watch', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'both selectors' => [[
        'payment-id' => '42',
        '--order-id' => 'order-42',
        '--to' => 'http://localhost/callback',
    ], 'exactly one'],
    'no destination' => [['payment-id' => '42'], '--to'],
    'invalid destination' => [['payment-id' => '42', '--to' => 'file:///tmp/callback'], 'valid HTTP or HTTPS URL'],
    'zero interval' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--interval' => '0'], '1 through 60'],
    'large interval' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--interval' => '61'], '1 through 60'],
    'fractional interval' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--interval' => '1.5'], '1 through 60'],
    'zero delivery attempts' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--delivery-attempts' => '0'], '1 through 60'],
    'large delivery attempts' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--delivery-attempts' => '61'], '1 through 60'],
    'fractional delivery attempts' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--delivery-attempts' => '1.5'], '1 through 60'],
    'non-numeric delivery attempts' => [['payment-id' => '42', '--to' => 'http://localhost/callback', '--delivery-attempts' => 'many'], '1 through 60'],
]);

it('accepts an account-wide watch without a payment selector', function () {
    app()->instance(CallbackWatcherFactory::class, new class extends CallbackWatcherFactory
    {
        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class implements CallbackWatcher
            {
                public function run(
                    ?string $paymentId,
                    ?string $orderId,
                    CallbackTarget $target,
                    string $apiKey,
                    string $privateKey,
                    int $interval,
                    int $deliveryAttempts,
                    Closure $observer,
                ): void {
                    expect($paymentId)->toBeNull()
                        ->and($orderId)->toBeNull()
                        ->and($target->url)->toBe('http://localhost/callback')
                        ->and($deliveryAttempts)->toBe(5);

                    $observer('watching-all', ['ready_at' => '2026-08-07T10:00:01+00:00']);
                    $observer('delivered', ['payment_id' => '42', 'operation_id' => '1', 'status' => 204]);
                    $observer('delivered', ['payment_id' => '43', 'operation_id' => '1', 'status' => 204]);
                }
            };
        }
    });
    Http::fake();

    $this->artisan('callbacks:watch', ['--to' => 'http://localhost/callback'])
        ->expectsOutputToContain('Watching all Quickpay payment callbacks')
        ->expectsOutputToContain('2026-08-07T10:00:01+00:00')
        ->expectsOutputToContain('payment 42 operation 1')
        ->expectsOutputToContain('payment 43 operation 1')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('documents foreground streaming behavior without offering json mode', function () {
    $command = new WatchCallbacksCommand;
    $definition = $command->getDefinition();

    expect($definition->hasOption('interval'))->toBeTrue()
        ->and($definition->getOption('interval')->getDefault())->toBe('2')
        ->and($definition->hasOption('delivery-attempts'))->toBeTrue()
        ->and($definition->getOption('delivery-attempts')->getDefault())->toBe('5')
        ->and($definition->getOption('delivery-attempts')->getDescription())
        ->toContain('initial POST')
        ->toContain('1-60')
        ->and($definition->hasOption('json'))->toBeFalse()
        ->and($definition->getArgument('payment-id')->getDescription())->toContain('Omit to watch all payments')
        ->and($command->getDescription())->toContain('Watch');
});

it('renders delivery classification ordering failure and recovery guidance in watch help', function () {
    $help = app(Kernel::class)->all()['help'];
    $tester = new CommandTester($help);

    $status = $tester->execute(['command_name' => 'callbacks:watch']);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Network/no-response failures and HTTP 408, 425, 429, and 5xx are retryable')
        ->toContain('All other unsuccessful HTTP statuses, including 405, and rejected redirects are terminal')
        ->toContain('Five attempts are made by default, including the initial POST, using --interval between attempts')
        ->toContain('Terminal or exhausted delivery exits with status 1')
        ->toContain('The failed operation is not skipped, and later operations are not delivered')
        ->toContain('callbacks:replay <payment-id> --to=<corrected-url>')
        ->toContain('resend the current payment state');
});

it('distinguishes Quickpay server callback urls from local forwarding in command help', function (string $commandClass) {
    $option = (new $commandClass)->getDefinition()->getOption('callback-url');

    expect($option->getDescription())
        ->toContain('Quickpay servers')
        ->toContain('not localhost');
})->with([
    CreatePaymentLinkCommand::class,
    CapturePaymentCommand::class,
    RefundPaymentCommand::class,
    CancelPaymentCommand::class,
]);

it('wires a foreground watch session and renders safe progress for every event', function () {
    app()->instance(CallbackWatcherFactory::class, new class extends CallbackWatcherFactory
    {
        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class implements CallbackWatcher
            {
                public function run(
                    ?string $paymentId,
                    ?string $orderId,
                    CallbackTarget $target,
                    string $apiKey,
                    string $privateKey,
                    int $interval,
                    int $deliveryAttempts,
                    Closure $observer,
                ): void {
                    expect($paymentId)->toBe('42')
                        ->and($orderId)->toBeNull()
                        ->and($target->url)->toBe('http://localhost/callback')
                        ->and($apiKey)->toBe('watch-api-key')
                        ->and($privateKey)->toBe('watch-private-key')
                        ->and($interval)->toBe(2)
                        ->and($deliveryAttempts)->toBe(7);

                    $observer('waiting-for-payment', ['order_id' => 'order-42']);
                    $observer('watching', ['payment_id' => '42', 'baseline_operations' => 1]);
                    $observer('payment-found', ['payment_id' => '42', 'order_id' => 'order-42']);
                    $observer('multiple-operations', ['count' => 2]);
                    $observer('delivery-retry', [
                        'attempt' => 1,
                        'maximum_attempts' => 7,
                        'delay' => 2,
                        'payment_id' => '42',
                        'operation_id' => '7',
                        'status' => 500,
                    ]);
                    $observer('polling-retry', ['status' => 429, 'delay' => 3]);
                    $observer('delivered', ['operation_id' => '7', 'status' => 204]);
                }
            };
        }
    });
    Http::fake();
    $command = new WatchCallbacksCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'payment-id' => '42',
        '--to' => 'http://localhost/callback',
        '--delivery-attempts' => '7',
    ]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Press Ctrl-C to stop')
        ->toContain('Waiting for order order-42')
        ->toContain('existing operation(s) baselined')
        ->toContain('created payment 42')
        ->toContain('same latest payment snapshot')
        ->toContain('attempt 1 of 7')
        ->toContain('payment 42 operation 7')
        ->toContain('HTTP 500')
        ->toContain('retrying in 2 second(s) before later operations')
        ->toContain('retrying in 3 second(s)')
        ->toContain('Delivered callback for operation 7');
    Http::assertNothingSent();
});

it('accepts delivery attempt validation boundaries and passes the chosen value to the watcher', function (string $attempts) {
    app()->instance(CallbackWatcherFactory::class, new class((int) $attempts) extends CallbackWatcherFactory
    {
        public function __construct(private readonly int $expectedAttempts) {}

        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class($this->expectedAttempts) implements CallbackWatcher
            {
                public function __construct(private readonly int $expectedAttempts) {}

                public function run(
                    ?string $paymentId,
                    ?string $orderId,
                    CallbackTarget $target,
                    string $apiKey,
                    string $privateKey,
                    int $interval,
                    int $deliveryAttempts,
                    Closure $observer,
                ): void {
                    expect($deliveryAttempts)->toBe($this->expectedAttempts);
                }
            };
        }
    });
    Http::fake();

    $this->artisan('callbacks:watch', [
        'payment-id' => '42',
        '--to' => 'http://localhost/callback',
        '--delivery-attempts' => $attempts,
    ])->assertExitCode(0);

    Http::assertNothingSent();
})->with(['minimum' => '1', 'maximum' => '60']);

it('routes terminal and exhausted delivery failures to redacted stderr with replay guidance', function (
    ?int $status,
    CallbackDeliveryFailure $failure,
    int $attempt,
    string $context,
    ?string $unexpectedContext,
) {
    app()->instance(CallbackWatcherFactory::class, new class($status, $failure, $attempt) extends CallbackWatcherFactory
    {
        public function __construct(
            private readonly ?int $status,
            private readonly CallbackDeliveryFailure $failure,
            private readonly int $attempt,
        ) {}

        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class($this->status, $this->failure, $this->attempt) implements CallbackWatcher
            {
                public function __construct(
                    private readonly ?int $status,
                    private readonly CallbackDeliveryFailure $failure,
                    private readonly int $attempt,
                ) {}

                public function run(
                    ?string $paymentId,
                    ?string $orderId,
                    CallbackTarget $target,
                    string $apiKey,
                    string $privateKey,
                    int $interval,
                    int $deliveryAttempts,
                    Closure $observer,
                ): void {
                    throw new CallbackDeliveryException(
                        paymentId: '42',
                        operationId: 'watch-api-key-operation',
                        delivery: new CallbackDelivery(
                            url: $target->url,
                            status: $this->status,
                            successful: false,
                            failure: $this->failure,
                        ),
                        attempt: $this->attempt,
                        maximumAttempts: $deliveryAttempts,
                    );
                }
            };
        }
    });
    Http::fake();
    $command = new WatchCallbacksCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $result = $tester->execute([
        'payment-id' => '42',
        '--to' => 'http://localhost/callback',
    ], ['capture_stderr_separately' => true]);
    $errorOutput = $tester->getErrorOutput();
    $semanticErrorOutput = preg_replace('/\s+/', ' ', $errorOutput) ?? $errorOutput;

    expect($result)->toBe(1)
        ->and($tester->getDisplay())->not->toContain('Callback delivery failed')
        ->and($semanticErrorOutput)
        ->toContain('Callback delivery failed for payment 42 operation [redacted]-operation')
        ->toContain($context)
        ->toContain('callbacks:replay 42')
        ->toContain('--to=<corrected-url>')
        ->not->toContain('watch-api-key');

    if ($unexpectedContext !== null) {
        expect($semanticErrorOutput)->not->toContain($unexpectedContext);
    }

    Http::assertNothingSent();
})->with([
    'terminal' => [405, CallbackDeliveryFailure::HttpResponse, 1, 'attempt 1 of 5 (HTTP 405)', null],
    'exhausted' => [503, CallbackDeliveryFailure::HttpResponse, 5, 'attempt 5 of 5 (HTTP 503)', null],
    'exhausted without response' => [null, CallbackDeliveryFailure::Network, 5, 'attempt 5 of 5 (no HTTP response: network failure)', null],
    'redirect rejected' => [null, CallbackDeliveryFailure::RedirectRejected, 1, 'attempt 1 of 5 (redirect rejected)', 'no HTTP response'],
]);
