<?php

namespace App\Callbacks;

use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayResponse;
use Closure;
use UnexpectedValueException;

/**
 * Polls one payment and forwards callbacks for operations observed after start.
 *
 * The runner is deliberately in-memory: it is a foreground development tool,
 * not a durable webhook relay. Each detected operation receives its own queued
 * immutable envelope. Delivery stays FIFO and retries block later operations,
 * matching Quickpay's same-resource ordering guarantee as closely as polling
 * permits.
 */
final class CallbackWatchRunner implements CallbackWatcher
{
    private readonly Closure $sleep;

    private readonly Closure $continue;

    public function __construct(
        private readonly PaymentLocator $locator,
        private readonly CallbackEnvelopeFactory $envelopes,
        private readonly CallbackForwarder $forwarder,
        ?callable $sleep = null,
        ?callable $continue = null,
    ) {
        $this->sleep = Closure::fromCallable($sleep ?? sleep(...));
        $this->continue = Closure::fromCallable($continue ?? static fn (): bool => true);
    }

    /**
     * @param  Closure(string, array<string, int|string|null>): void  $observer
     */
    public function run(
        ?string $paymentId,
        ?string $orderId,
        CallbackTarget $target,
        string $apiKey,
        string $privateKey,
        int $interval,
        Closure $observer,
    ): void {
        $payment = $this->poll(
            $paymentId !== null
                ? fn (): QuickpayResponse => $this->locator->byId($paymentId)
                : fn (): ?QuickpayResponse => $this->locator->byOrderId($orderId ?? ''),
            $interval,
            $observer,
        );
        $known = [];

        if ($payment === null) {
            $observer('waiting-for-payment', ['order_id' => $orderId]);
        } else {
            $paymentId = $this->paymentId($payment->json);
            $known = array_fill_keys(array_column($this->operations($payment->json), 'id'), true);
            $observer('watching', ['payment_id' => $paymentId, 'baseline_operations' => count($known)]);
        }

        while (($this->continue)()) {
            ($this->sleep)($interval);

            if ($paymentId === null) {
                $payment = $this->poll(
                    fn (): ?QuickpayResponse => $this->locator->byOrderId($orderId ?? ''),
                    $interval,
                    $observer,
                );

                if ($payment === null) {
                    continue;
                }

                $paymentId = $this->paymentId($payment->json);
                $observer('payment-found', ['payment_id' => $paymentId, 'order_id' => $orderId]);
            } else {
                $payment = $this->poll(
                    fn (): QuickpayResponse => $this->locator->byId($paymentId),
                    $interval,
                    $observer,
                );
            }

            if ($payment === null) {
                continue;
            }

            $new = array_values(array_filter(
                $this->operations($payment->json),
                fn (array $operation): bool => ! isset($known[$operation['id']]),
            ));

            if (count($new) > 1) {
                // Polling observes only the latest resource snapshot. One POST
                // is still made per operation, but same-poll bodies are equal.
                $observer('multiple-operations', ['count' => count($new)]);
            }

            foreach ($new as $operation) {
                $envelope = $this->envelopes->make(
                    $payment,
                    $apiKey,
                    $privateKey,
                    $operation['id'],
                );

                do {
                    $delivery = $this->forwarder->deliver($target, $envelope);

                    if (! $delivery->successful) {
                        $observer('delivery-retry', [
                            'operation_id' => $operation['id'],
                            'status' => $delivery->status,
                        ]);
                        ($this->sleep)($interval);
                    }
                } while (! $delivery->successful);

                $known[$operation['id']] = true;
                $observer('delivered', [
                    'operation_id' => $operation['id'],
                    'status' => $delivery->status,
                ]);
            }
        }
    }

    /**
     * @param  Closure(): (?QuickpayResponse)  $request
     * @param  Closure(string, array<string, int|string|null>): void  $observer
     */
    private function poll(Closure $request, int $interval, Closure $observer): ?QuickpayResponse
    {
        while (true) {
            try {
                return $request();
            } catch (CallbackPollingException $exception) {
                if (! $exception->retryable) {
                    throw $exception;
                }

                $delay = $exception->retryAfter ?? $interval;
                $observer('polling-retry', ['status' => $exception->status, 'delay' => $delay]);
                ($this->sleep)($delay);
            } catch (QuickpayRequestException) {
                // QuickpayClient emits this safe generic exception for network
                // failures; GET polling can be retried without mutation risk.
                $observer('polling-retry', ['status' => null, 'delay' => $interval]);
                ($this->sleep)($interval);
            }
        }
    }

    private function paymentId(mixed $payment): string
    {
        $id = is_array($payment) ? ($payment['id'] ?? null) : null;

        if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
            throw new UnexpectedValueException('Quickpay returned a payment without a valid payment ID.');
        }

        return (string) $id;
    }

    /**
     * @return array<int, array{id: string, created_at: string}>
     */
    private function operations(mixed $payment): array
    {
        $operations = is_array($payment) ? ($payment['operations'] ?? []) : null;

        if (! is_array($operations) || ! array_is_list($operations)) {
            throw new UnexpectedValueException('Quickpay returned a payment with malformed operations.');
        }

        $normalized = array_map(function (mixed $operation): array {
            $id = is_array($operation) ? ($operation['id'] ?? null) : null;

            if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
                throw new UnexpectedValueException('Quickpay returned an operation without a valid operation ID.');
            }

            return [
                'id' => (string) $id,
                'created_at' => isset($operation['created_at']) && is_scalar($operation['created_at'])
                    ? (string) $operation['created_at']
                    : '',
            ];
        }, $operations);

        usort($normalized, fn (array $left, array $right): int => [
            $left['created_at'],
            str_pad($left['id'], 20, '0', STR_PAD_LEFT),
        ] <=> [
            $right['created_at'],
            str_pad($right['id'], 20, '0', STR_PAD_LEFT),
        ]);

        return $normalized;
    }
}
