<?php

namespace App\Callbacks\Watching;

use App\Quickpay\Exceptions\QuickpayRequestException;

/**
 * Preserves only the response metadata needed for safe polling decisions.
 */
final class CallbackPollingException extends QuickpayRequestException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly bool $retryable,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
