<?php

namespace App\Commands\Concerns;

use App\Callbacks\CallbackTarget;
use InvalidArgumentException;

/**
 * Applies the shared selector and destination contract for callback commands.
 */
trait InteractsWithCallbackInput
{
    /** @return array{0: ?string, 1: ?string} */
    protected function callbackSelector(): array
    {
        $payment = $this->argument('payment-id');
        $order = $this->option('order-id');
        $hasPayment = $payment !== null && $payment !== '';
        $hasOrder = is_string($order) && $order !== '';

        if (! $hasPayment && ! $hasOrder) {
            throw new InvalidArgumentException('Provide a payment ID or --order-id.');
        }

        if ($hasPayment && $hasOrder) {
            throw new InvalidArgumentException('Provide exactly one selector: a payment ID or --order-id.');
        }

        return [
            $hasPayment ? (string) $this->positiveInteger($payment, 'payment-id') : null,
            $hasOrder ? $order : null,
        ];
    }

    protected function callbackTarget(): CallbackTarget
    {
        $destination = $this->option('to');

        if (! is_string($destination) || $destination === '') {
            throw new InvalidArgumentException('The --to option is required.');
        }

        return CallbackTarget::fromString($destination);
    }
}
