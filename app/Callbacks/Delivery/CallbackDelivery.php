<?php

namespace App\Callbacks\Delivery;

/**
 * Safe delivery metadata suitable for terminal and JSON summaries.
 *
 * Local response bodies are deliberately absent: they may contain application
 * secrets and are not needed to decide whether Quickpay would accept delivery.
 */
final readonly class CallbackDelivery
{
    public function __construct(
        public string $url,
        public ?int $status,
        public bool $successful,
        public int $redirects = 0,
        public ?CallbackDeliveryFailure $failure = null,
    ) {
        if ($this->successful && $this->failure !== null) {
            throw new \InvalidArgumentException('Successful deliveries cannot have a failure reason.');
        }
    }

    public function isRetryable(): bool
    {
        if ($this->successful) {
            return false;
        }

        return $this->failure === CallbackDeliveryFailure::Network
            || ($this->failure === CallbackDeliveryFailure::HttpResponse
                && ($this->status === 408
                    || $this->status === 425
                    || $this->status === 429
                    || ($this->status !== null && $this->status >= 500 && $this->status <= 599)));
    }
}
