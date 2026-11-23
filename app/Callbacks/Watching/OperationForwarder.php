<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use App\Quickpay\QuickpayResponse;
use Closure;

/**
 * Signs and forwards newly observed operations in order, retrying each one
 * before any later operation is sent.
 *
 * Each operation receives its own immutable envelope. A terminal or exhausted
 * delivery throws, so the failed operation is never skipped.
 */
final readonly class OperationForwarder
{
    /** @param Closure(int): mixed $sleep */
    public function __construct(
        private CallbackEnvelopeFactory $envelopes,
        private CallbackForwarder $forwarder,
        private Closure $sleep,
    ) {}

    /**
     * @param  list<string>  $operationIds  in delivery order
     */
    public function forward(
        QuickpayResponse $payment,
        string $paymentId,
        array $operationIds,
        CallbackWatchRequest $request,
        CallbackWatchObserver $observer,
    ): void {
        if (count($operationIds) > 1) {
            // Polling observes only the latest resource snapshot. One POST
            // is still made per operation, but same-poll bodies are equal.
            $observer->multipleOperations(count($operationIds));
        }

        foreach ($operationIds as $operationId) {
            $this->deliver($payment, $paymentId, $operationId, $request, $observer);
        }
    }

    private function deliver(
        QuickpayResponse $payment,
        string $paymentId,
        string $operationId,
        CallbackWatchRequest $request,
        CallbackWatchObserver $observer,
    ): void {
        $envelope = $this->envelopes->make(
            $payment,
            $request->apiKey,
            $request->privateKey,
        );

        $attempt = 0;

        do {
            $attempt++;
            $delivery = $this->forwarder->deliver($request->target, $envelope);

            if ($delivery->successful) {
                break;
            }

            if (! $delivery->isRetryable() || $attempt >= $request->deliveryAttempts) {
                throw new CallbackDeliveryException(
                    $paymentId,
                    $operationId,
                    $delivery,
                    $attempt,
                    $request->deliveryAttempts,
                );
            }

            $observer->deliveryRetrying(
                paymentId: $paymentId,
                operationId: $operationId,
                attempt: $attempt,
                maximumAttempts: $request->deliveryAttempts,
                delay: $request->interval,
                status: $delivery->status,
            );
            ($this->sleep)($request->interval);
        } while (true);

        $observer->delivered($paymentId, $operationId, $delivery->status);
    }
}
