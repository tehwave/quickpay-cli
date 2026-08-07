<?php

namespace App\Commands\Callbacks;

use App\Callbacks\Input\CallbackRequest;
use App\Callbacks\WatchCallbacks;
use App\Console\AuthenticatedCommand;
use App\Console\Input\IntegerInput;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use InvalidArgumentException;

/**
 * Streams future payment changes as signed callbacks to a local endpoint.
 *
 * Existing operations form a baseline so starting the command has no replay
 * side effect. Selecting a not-yet-created order is different: every operation
 * present when that payment first appears happened during the watch session and
 * is therefore forwarded.
 */
class WatchCallbacksCommand extends AuthenticatedCommand
{
    protected $signature = 'callbacks:watch
        {payment-id? : Quickpay payment ID}
        {--order-id= : Wait for and watch one payment by its order ID}
        {--to= : HTTP or HTTPS callback destination}
        {--interval=2 : Poll and retry interval in seconds (1-60)}';

    protected $description = 'Watch a payment and stream signed Quickpay callbacks';

    public function handle(
        AuthenticatedQuickpayFactory $quickpay,
        WatchCallbacks $watch,
    ): int {
        return $this->withQuickpay(
            $quickpay,
            function (AuthenticatedQuickpay $authenticated) use ($watch): int {
                $request = CallbackRequest::from(
                    $this->argument('payment-id'),
                    $this->option('order-id'),
                    $this->option('to'),
                );
                $interval = $this->interval();
                $apiKey = $authenticated->apiKey->value();

                $this->info(ResponseBodySanitizer::terminalLine(
                    "Watching for Quickpay payment callbacks to {$request->target->url}. Press Ctrl-C to stop.",
                    $apiKey,
                ));
                $watch->execute(
                    $authenticated->client,
                    $apiKey,
                    $request,
                    $interval,
                    function (string $event, array $context) use ($apiKey): void {
                        $this->writeWatchEvent($event, $context, $apiKey);
                    },
                );

                return self::SUCCESS;
            },
        );
    }

    private function interval(): int
    {
        try {
            $interval = IntegerInput::positive($this->option('interval'), 'interval');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('interval must be an integer from 1 through 60.');
        }

        if ($interval > 60) {
            throw new InvalidArgumentException('interval must be an integer from 1 through 60.');
        }

        return $interval;
    }

    /** @param array<string, int|string|null> $context */
    private function writeWatchEvent(string $event, array $context, string $apiKey): int
    {
        $value = fn (string $key): string => ResponseBodySanitizer::terminalLine((string) ($context[$key] ?? '-'), $apiKey);

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
