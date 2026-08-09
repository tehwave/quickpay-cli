<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackDelivery;
use App\Callbacks\Delivery\CallbackDeliveryFailure;
use UnexpectedValueException;

/**
 * Stops a foreground watch when its FIFO delivery cannot continue safely.
 */
final class CallbackDeliveryException extends UnexpectedValueException
{
    public function __construct(
        string $paymentId,
        string $operationId,
        CallbackDelivery $delivery,
        int $attempt,
        int $maximumAttempts,
    ) {
        $context = $delivery->failure === CallbackDeliveryFailure::RedirectRejected
            ? 'redirect rejected'
            : ($delivery->status !== null
                ? "HTTP {$delivery->status}"
                : 'no HTTP response: '.match ($delivery->failure) {
                    CallbackDeliveryFailure::Network => 'network failure',
                    default => 'delivery failure',
                });

        parent::__construct(
            "Callback delivery failed for payment {$paymentId} operation {$operationId} "
            ."after attempt {$attempt} of {$maximumAttempts} ({$context}). "
            ."Fix the endpoint, then run `callbacks:replay {$paymentId} --to=<corrected-url>`.",
        );
    }
}
