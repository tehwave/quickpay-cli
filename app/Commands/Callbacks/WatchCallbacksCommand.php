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
 * Account-wide watching uses an explicit readiness timestamp, while scoped
 * watches baseline a selected payment's existing operations. Neither mode
 * replays operations that predate the session.
 */
class WatchCallbacksCommand extends AuthenticatedCommand
{
    protected $signature = 'callbacks:watch
        {payment-id? : Quickpay payment ID. Omit to watch all payments}
        {--order-id= : Wait for and watch one payment by its order ID}
        {--to= : HTTP or HTTPS callback destination}
        {--interval=2 : Poll and retry interval in seconds (1-60)}';

    protected $description = 'Watch payment operations and stream signed Quickpay callbacks';

    public function handle(
        AuthenticatedQuickpayFactory $quickpay,
        WatchCallbacks $watch,
    ): int {
        return $this->withQuickpay(
            $quickpay,
            function (AuthenticatedQuickpay $authenticated) use ($watch): int {
                $request = CallbackRequest::forWatch(
                    $this->argument('payment-id'),
                    $this->option('order-id'),
                    $this->option('to'),
                );
                $interval = $this->interval();
                $apiKey = $authenticated->apiKey->value();

                if ($request->paymentId !== null || $request->orderId !== null) {
                    $this->info(ResponseBodySanitizer::terminalLine(
                        "Watching for Quickpay payment callbacks to {$request->target->url}. Press Ctrl-C to stop.",
                        $apiKey,
                    ));
                }
                $watch->execute(
                    $authenticated->client,
                    $apiKey,
                    $request,
                    $interval,
                    function (string $event, array $context) use ($apiKey, $request): void {
                        $this->writeWatchEvent($event, $context, $apiKey, $request->target->url);
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
    private function writeWatchEvent(string $event, array $context, string $apiKey, string $destination): int
    {
        $value = fn (string $key): string => ResponseBodySanitizer::terminalLine((string) ($context[$key] ?? '-'), $apiKey);
        $safeDestination = ResponseBodySanitizer::terminalLine($destination, $apiKey);
        $operation = isset($context['payment_id'])
            ? "payment {$value('payment_id')} operation {$value('operation_id')}"
            : "operation {$value('operation_id')}";

        if ($event === 'watching-all') {
            $this->info('Watching all Quickpay payment callbacks. Press Ctrl-C to stop.');
            $this->line("Ready at {$value('ready_at')}; forwarding to {$safeDestination}.");

            return self::SUCCESS;
        }

        match ($event) {
            'waiting-for-payment' => $this->line("Waiting for order {$value('order_id')} to create a payment."),
            'watching' => $this->line("Payment {$value('payment_id')} ready; {$value('baseline_operations')} existing operation(s) baselined."),
            'payment-found' => $this->line("Order {$value('order_id')} created payment {$value('payment_id')}."),
            'multiple-operations' => $this->warn("Detected {$value('count')} operations in one poll; forwarding one callback per operation with the same latest payment snapshot."),
            'delivery-retry' => $this->warn("Callback for {$operation} failed (HTTP {$value('status')}); retrying before later operations."),
            'polling-retry' => $this->warn("Quickpay polling failed (HTTP {$value('status')}); retrying in {$value('delay')} second(s)."),
            'delivered' => $this->info("Delivered callback for {$operation} with HTTP {$value('status')}."),
            default => null,
        };

        return self::SUCCESS;
    }
}
