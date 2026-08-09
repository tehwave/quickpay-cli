<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayResponse;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Polls all payments or one selected payment for operations observed after start.
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

    private readonly Closure $clock;

    private readonly Closure $waitUntil;

    public function __construct(
        private readonly PaymentLocator $locator,
        private readonly CallbackEnvelopeFactory $envelopes,
        private readonly CallbackForwarder $forwarder,
        ?callable $sleep = null,
        ?callable $continue = null,
        ?callable $clock = null,
        ?callable $waitUntil = null,
    ) {
        $this->sleep = Closure::fromCallable($sleep ?? sleep(...));
        $this->continue = Closure::fromCallable($continue ?? static fn (): bool => true);
        $this->clock = Closure::fromCallable(
            $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $this->waitUntil = Closure::fromCallable($waitUntil ?? function (DateTimeImmutable $target): void {
            $remaining = (float) $target->format('U.u') - (float) (($this->clock)())->format('U.u');

            if ($remaining > 0) {
                usleep((int) ceil($remaining * 1_000_000));
            }
        });
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
        int $deliveryAttempts,
        Closure $observer,
    ): void {
        if ($paymentId === null && $orderId === null) {
            $this->runAccountWide($target, $apiKey, $privateKey, $interval, $deliveryAttempts, $observer);

            return;
        }

        $payment = $this->poll(
            $paymentId !== null
                ? fn (): QuickpayResponse => $this->locator->byId($paymentId)
                : fn (): ?QuickpayResponse => $this->locator->byOrderId($orderId),
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
                    fn (): ?QuickpayResponse => $this->locator->byOrderId($orderId),
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
                $this->deliverOperation(
                    $payment,
                    $paymentId,
                    $operation['id'],
                    $apiKey,
                    $privateKey,
                    $target,
                    $interval,
                    $deliveryAttempts,
                    $observer,
                );
                $known[$operation['id']] = true;
            }
        }
    }

    /** @param Closure(string, array<string, int|string|null>): void $observer */
    private function runAccountWide(
        CallbackTarget $target,
        string $apiKey,
        string $privateKey,
        int $interval,
        int $deliveryAttempts,
        Closure $observer,
    ): void {
        $readiness = $this->nextWholeUtcSecond(($this->clock)());
        ($this->waitUntil)($readiness);
        $observer('watching-all', ['ready_at' => $readiness->format(DateTimeImmutable::ATOM)]);
        $watermark = $readiness;
        $known = [];

        while (($this->continue)()) {
            ($this->sleep)($interval);
            $windowEnd = $this->wholeUtcSecond(($this->clock)());

            if ($windowEnd < $watermark) {
                throw new UnexpectedValueException('The callback watcher clock moved before its payment watermark.');
            }

            $payments = $this->poll(
                fn (): array => $this->locator->changedBetween($watermark->modify('-1 second'), $windowEnd),
                $interval,
                $observer,
            );
            $watermark = $windowEnd;

            foreach ($payments as $payment) {
                $paymentId = $this->paymentId($payment->json);
                $operations = array_values(array_filter(
                    $this->operations($payment->json),
                    fn (array $operation): bool => $operation['created_at'] >= $readiness
                        && ! isset($known[$paymentId][$operation['id']]),
                ));

                if (count($operations) > 1) {
                    $observer('multiple-operations', ['count' => count($operations)]);
                }

                foreach ($operations as $operation) {
                    $this->deliverOperation(
                        $payment,
                        $paymentId,
                        $operation['id'],
                        $apiKey,
                        $privateKey,
                        $target,
                        $interval,
                        $deliveryAttempts,
                        $observer,
                    );
                    $known[$paymentId][$operation['id']] = true;
                }
            }
        }
    }

    /** @param Closure(string, array<string, int|string|null>): void $observer */
    private function deliverOperation(
        QuickpayResponse $payment,
        string $paymentId,
        string $operationId,
        string $apiKey,
        string $privateKey,
        CallbackTarget $target,
        int $interval,
        int $deliveryAttempts,
        Closure $observer,
    ): void {
        $envelope = $this->envelopes->make(
            $payment,
            $apiKey,
            $privateKey,
            $operationId,
        );

        $attempt = 0;

        do {
            $attempt++;
            $delivery = $this->forwarder->deliver($target, $envelope);

            if ($delivery->successful) {
                break;
            }

            if (! $delivery->isRetryable() || $attempt >= $deliveryAttempts) {
                throw new CallbackDeliveryException(
                    $paymentId,
                    $operationId,
                    $delivery,
                    $attempt,
                    $deliveryAttempts,
                );
            }

            $observer('delivery-retry', [
                'attempt' => $attempt,
                'maximum_attempts' => $deliveryAttempts,
                'delay' => $interval,
                'payment_id' => $paymentId,
                'operation_id' => $operationId,
                'status' => $delivery->status,
            ]);
            ($this->sleep)($interval);
        } while (true);

        $observer('delivered', [
            'payment_id' => $paymentId,
            'operation_id' => $operationId,
            'status' => $delivery->status,
        ]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @param  Closure(string, array<string, int|string|null>): void  $observer
     * @return T
     */
    private function poll(Closure $request, int $interval, Closure $observer): mixed
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

    private function nextWholeUtcSecond(DateTimeImmutable $time): DateTimeImmutable
    {
        return $this->wholeUtcSecond($time)->modify('+1 second');
    }

    private function wholeUtcSecond(DateTimeImmutable $time): DateTimeImmutable
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );
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
     * @return array<int, array{id: string, created_at: DateTimeImmutable}>
     */
    private function operations(mixed $payment): array
    {
        $operations = is_array($payment) ? ($payment['operations'] ?? null) : null;

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
                'created_at' => $this->operationTimestamp($operation['created_at'] ?? null),
            ];
        }, $operations);

        usort($normalized, fn (array $left, array $right): int => [
            (int) $left['created_at']->format('U'),
            (int) $left['created_at']->format('u'),
            str_pad($left['id'], 20, '0', STR_PAD_LEFT),
        ] <=> [
            (int) $right['created_at']->format('U'),
            (int) $right['created_at']->format('u'),
            str_pad($right['id'], 20, '0', STR_PAD_LEFT),
        ]);

        return $normalized;
    }

    private function operationTimestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            throw new UnexpectedValueException('Quickpay returned an operation without a valid timestamp.');
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $timestamp = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new UnexpectedValueException('Quickpay returned an operation without a valid timestamp.');
        }

        return $timestamp->setTimezone(new DateTimeZone('UTC'));
    }
}
