<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Resolution\PaymentLocator;
use App\Quickpay\QuickpayResponse;
use Closure;
use LogicException;

/**
 * Watches one payment, selected by payment ID or by order ID.
 *
 * Operations that exist when the payment is first seen are baselined and never
 * forwarded. An order without a payment is polled until Quickpay creates one;
 * every operation on that new payment is then forwarded.
 */
final readonly class SinglePaymentWatch
{
    /**
     * @param  Closure(int): mixed  $sleep
     * @param  Closure(): bool  $continue
     */
    public function __construct(
        private PaymentLocator $locator,
        private WatchPoller $poller,
        private OperationForwarder $forwarder,
        private Closure $sleep,
        private Closure $continue,
    ) {}

    public function run(CallbackWatchRequest $request, CallbackWatchObserver $observer): void
    {
        $paymentId = $request->paymentId;
        $payment = $paymentId !== null
            ? $this->byId($paymentId, $request, $observer)
            : $this->byOrderId($request, $observer);
        $known = [];

        if ($payment === null) {
            $observer->waitingForPayment($this->orderId($request));
        } else {
            $paymentId = WatchedPayment::id($payment->json);
            $known = array_fill_keys(array_column(WatchedPayment::operations($payment->json), 'id'), true);
            $observer->watchingPayment($paymentId, count($known));
        }

        while (($this->continue)()) {
            ($this->sleep)($request->interval);

            if ($paymentId === null) {
                $payment = $this->byOrderId($request, $observer);

                if ($payment === null) {
                    continue;
                }

                $paymentId = WatchedPayment::id($payment->json);
                $observer->paymentFound($paymentId, $this->orderId($request));
            } else {
                $payment = $this->byId($paymentId, $request, $observer);
            }

            $new = array_values(array_filter(
                array_column(WatchedPayment::operations($payment->json), 'id'),
                fn (string $operationId): bool => ! isset($known[$operationId]),
            ));

            $this->forwarder->forward($payment, $paymentId, $new, $request, $observer);
            $known += array_fill_keys($new, true);
        }
    }

    private function byId(string $paymentId, CallbackWatchRequest $request, CallbackWatchObserver $observer): QuickpayResponse
    {
        return $this->poller->poll(
            fn (): QuickpayResponse => $this->locator->byId($paymentId),
            $request->interval,
            $observer,
        );
    }

    private function byOrderId(CallbackWatchRequest $request, CallbackWatchObserver $observer): ?QuickpayResponse
    {
        $orderId = $this->orderId($request);

        return $this->poller->poll(
            fn (): ?QuickpayResponse => $this->locator->byOrderId($orderId),
            $request->interval,
            $observer,
        );
    }

    private function orderId(CallbackWatchRequest $request): string
    {
        return $request->orderId
            ?? throw new LogicException('A payment watch without a payment ID needs an order ID.');
    }
}
