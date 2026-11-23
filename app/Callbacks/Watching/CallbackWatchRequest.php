<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackTarget;

/**
 * Describes one foreground watch session: what to watch, where to forward and
 * how to sign, poll and retry.
 *
 * Without a payment ID or order ID the session watches every payment on the
 * account.
 */
final readonly class CallbackWatchRequest
{
    public function __construct(
        public ?string $paymentId,
        public ?string $orderId,
        public CallbackTarget $target,
        public string $apiKey,
        public string $privateKey,
        public int $interval,
        public int $deliveryAttempts,
    ) {}

    public function isAccountWide(): bool
    {
        return $this->paymentId === null && $this->orderId === null;
    }
}
