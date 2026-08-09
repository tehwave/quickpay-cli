<?php

namespace App\Commands\Callbacks;

use App\Callbacks\Input\CallbackRequest;
use App\Callbacks\ReplayCallback;
use App\Callbacks\ReplayResult;
use App\Console\AuthenticatedCommand;
use App\Console\Output\JsonOutput;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;

/**
 * Replays Quickpay's current payment callback to an explicit destination.
 *
 * This reproduces the callback envelope developers need locally, not historic
 * delivery metadata such as Quickpay source IPs or the resource's old state.
 */
class ReplayCallbackCommand extends AuthenticatedCommand
{
    protected $signature = 'callbacks:replay
        {payment-id? : Quickpay payment ID}
        {--order-id= : Resolve one payment by its order ID}
        {--to= : HTTP or HTTPS callback destination}
        {--json : Write a machine-readable delivery summary}';

    protected $description = 'Replay a signed Quickpay payment callback';

    public function handle(
        AuthenticatedQuickpayFactory $quickpay,
        ReplayCallback $replay,
    ): int {
        return $this->withQuickpay(
            $quickpay,
            fn (AuthenticatedQuickpay $authenticated): int => $this->present(
                $replay->execute(
                    $authenticated->client,
                    $authenticated->apiKey->value(),
                    CallbackRequest::from(
                        $this->argument('payment-id'),
                        $this->option('order-id'),
                        $this->option('to'),
                    ),
                ),
                $authenticated->apiKey->value(),
            ),
        );
    }

    private function present(ReplayResult $result, string $apiKey): int
    {
        $envelope = $result->envelope;
        $delivery = $result->delivery;
        $summary = [
            'payment_id' => $envelope->paymentId,
            'order_id' => $envelope->orderId,
            'destination' => $delivery->url,
            'status' => $delivery->status,
            'success' => $delivery->successful,
        ];

        if ($this->option('json')) {
            $this->getOutput()->write(JsonOutput::value($summary, $apiKey));

            return $delivery->successful ? self::SUCCESS : self::FAILURE;
        }

        if (! $delivery->successful) {
            $status = $delivery->status === null ? 'without an HTTP response' : "with HTTP {$delivery->status}";

            return $this->failure(ResponseBodySanitizer::terminalLine("Callback delivery failed {$status}.", $apiKey));
        }

        $order = $envelope->orderId === null ? '' : " (order {$envelope->orderId})";
        $message = "Delivered callback for payment {$envelope->paymentId}{$order} to {$delivery->url} with HTTP {$delivery->status}.";
        $this->info(ResponseBodySanitizer::terminalLine($message, $apiKey));

        return self::SUCCESS;
    }
}
