<?php

namespace App\Commands;

use App\Callbacks\AccountPrivateKeyFetcher;
use App\Callbacks\CallbackWatcherFactory;
use App\Callbacks\PrivateKeyResolver;
use App\Commands\Concerns\InteractsWithCallbackInput;
use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/**
 * Streams future payment changes as signed callbacks to a local endpoint.
 *
 * Existing operations form a baseline so starting the command has no replay
 * side effect. Selecting a not-yet-created order is different: every operation
 * present when that payment first appears happened during the watch session and
 * is therefore forwarded.
 */
class CallbacksWatchCommand extends Command
{
    use InteractsWithCallbackInput;
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    protected $signature = 'callbacks:watch
        {payment-id? : Quickpay payment ID}
        {--order-id= : Wait for and watch one payment by its order ID}
        {--to= : HTTP or HTTPS callback destination}
        {--interval=2 : Poll and retry interval in seconds (1-60)}';

    protected $description = 'Watch a payment and stream signed Quickpay callbacks';

    public function handle(
        CredentialStore $credentials,
        QuickpayClientFactory $clients,
        Factory $http,
        CallbackWatcherFactory $watchers,
    ): int {
        return $this->withQuickpay(
            $credentials,
            $clients,
            fn (QuickpayClient $quickpay, string $apiKey): int => $this->watch($quickpay, $http, $watchers, $apiKey),
        );
    }

    private function watch(
        QuickpayClient $quickpay,
        Factory $http,
        CallbackWatcherFactory $watchers,
        string $apiKey,
    ): int {
        [$paymentId, $orderId] = $this->callbackSelector();
        $target = $this->callbackTarget();
        try {
            $interval = $this->positiveInteger($this->option('interval'), 'interval');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('interval must be an integer from 1 through 60.');
        }

        if ($interval > 60) {
            throw new InvalidArgumentException('interval must be an integer from 1 through 60.');
        }

        $privateKey = (new PrivateKeyResolver(
            fn (): string => (new AccountPrivateKeyFetcher)->fetch($quickpay),
        ))->resolve();
        $runner = $watchers->make($quickpay, $http);

        $this->info($this->safeTerminalText(
            "Watching for Quickpay payment callbacks to {$target->url}. Press Ctrl-C to stop.",
            $apiKey,
        ));
        $runner->run(
            paymentId: $paymentId,
            orderId: $orderId,
            target: $target,
            apiKey: $apiKey,
            privateKey: $privateKey,
            interval: $interval,
            observer: function (string $event, array $context) use ($apiKey): void {
                $this->writeWatchEvent($event, $context, $apiKey);
            },
        );

        return self::SUCCESS;
    }

    /** @param array<string, int|string|null> $context */
    private function writeWatchEvent(string $event, array $context, string $apiKey): int
    {
        $value = fn (string $key): string => $this->safeTerminalText((string) ($context[$key] ?? '-'), $apiKey);

        match ($event) {
            'waiting-for-payment' => $this->line("Waiting for order {$value('order_id')} to create a payment."),
            'watching' => $this->line("Payment {$value('payment_id')} ready; {$value('baseline_operations')} existing operation(s) baselined."),
            'payment-found' => $this->line("Order {$value('order_id')} created payment {$value('payment_id')}."),
            'multiple-operations' => $this->warn("Detected {$value('count')} operations in one poll; forwarding one callback per operation with the same latest payment snapshot."),
            'delivery-retry' => $this->warn("Callback for operation {$value('operation_id')} failed (HTTP {$value('status')}); retrying before later operations."),
            'polling-retry' => $this->warn("Quickpay polling failed (HTTP {$value('status')}); retrying in {$value('delay')} second(s)."),
            'delivered' => $this->info("Delivered callback for operation {$value('operation_id')} with HTTP {$value('status')}."),
            default => null,
        };

        return self::SUCCESS;
    }
}
