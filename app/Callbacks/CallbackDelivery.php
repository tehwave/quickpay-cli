<?php

namespace App\Callbacks;

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
    ) {}
}
