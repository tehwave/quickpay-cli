<?php

namespace App\Callbacks;

/**
 * Immutable callback bytes and headers captured before delivery.
 *
 * Keeping the envelope immutable is important for retries: a failed delivery
 * must be retried with the same body and therefore the same HMAC signature.
 */
final readonly class CallbackEnvelope
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $paymentId,
        public ?string $orderId,
        public string $body,
        public array $headers,
        public ?string $operationId = null,
    ) {}
}
