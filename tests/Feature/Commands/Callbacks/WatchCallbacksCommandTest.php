<?php

use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Watching\CallbackWatcher;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Commands\Callbacks\WatchCallbacksCommand;
use App\Commands\Payments\CancelPaymentCommand;
use App\Commands\Payments\CapturePaymentCommand;
use App\Commands\Payments\CreatePaymentLinkCommand;
use App\Commands\Payments\RefundPaymentCommand;
use App\Quickpay\QuickpayClient;
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
    'no selector' => [['--to' => 'http://localhost/callback'], 'payment ID or --order-id'],
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
]);

it('documents foreground streaming behavior without offering json mode', function () {
    $command = new WatchCallbacksCommand;
    $definition = $command->getDefinition();

    expect($definition->hasOption('interval'))->toBeTrue()
        ->and($definition->getOption('interval')->getDefault())->toBe('2')
        ->and($definition->hasOption('json'))->toBeFalse()
        ->and($command->getDescription())->toContain('Watch');
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
                    Closure $observer,
                ): void {
                    expect($paymentId)->toBe('42')
                        ->and($orderId)->toBeNull()
                        ->and($target->url)->toBe('http://localhost/callback')
                        ->and($apiKey)->toBe('watch-api-key')
                        ->and($privateKey)->toBe('watch-private-key')
                        ->and($interval)->toBe(2);

                    $observer('waiting-for-payment', ['order_id' => 'order-42']);
                    $observer('watching', ['payment_id' => '42', 'baseline_operations' => 1]);
                    $observer('payment-found', ['payment_id' => '42', 'order_id' => 'order-42']);
                    $observer('multiple-operations', ['count' => 2]);
                    $observer('delivery-retry', ['operation_id' => '7', 'status' => 500]);
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
    ]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Press Ctrl-C to stop')
        ->toContain('Waiting for order order-42')
        ->toContain('existing operation(s) baselined')
        ->toContain('created payment 42')
        ->toContain('same latest payment snapshot')
        ->toContain('retrying before later operations')
        ->toContain('retrying in 3 second(s)')
        ->toContain('Delivered callback for operation 7');
    Http::assertNothingSent();
});
