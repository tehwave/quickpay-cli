<?php

namespace App\Callbacks\Watching;

use DateTimeImmutable;

/**
 * Receives the progress of one watch session.
 *
 * One method per event keeps every event and its data typed, so a presenter
 * cannot miss or misspell one.
 */
interface CallbackWatchObserver
{
    /** Account-wide watching forwards operations created at or after $readyAt. */
    public function watchingAllPayments(DateTimeImmutable $readyAt): void;

    /** The watched order has no payment yet. */
    public function waitingForPayment(string $orderId): void;

    /** The watched payment exists and its current operations are baselined. */
    public function watchingPayment(string $paymentId, int $baselineOperations): void;

    /** The watched order created its payment after the session started. */
    public function paymentFound(string $paymentId, string $orderId): void;

    /** One poll found several new operations on the same payment snapshot. */
    public function multipleOperations(int $count): void;

    public function deliveryRetrying(
        string $paymentId,
        string $operationId,
        int $attempt,
        int $maximumAttempts,
        int $delay,
        ?int $status,
    ): void;

    /** A retryable Quickpay polling failure; $status is null without an HTTP response. */
    public function pollingRetrying(?int $status, int $delay): void;

    public function delivered(string $paymentId, string $operationId, ?int $status): void;
}
