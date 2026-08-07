<?php

namespace App\Callbacks\Input;

use App\Callbacks\Delivery\CallbackTarget;
use App\Console\Input\IntegerInput;
use InvalidArgumentException;

final readonly class CallbackRequest
{
    private function __construct(
        public ?string $paymentId,
        public ?string $orderId,
        public CallbackTarget $target,
    ) {}

    public static function from(mixed $payment, mixed $order, mixed $destination): self
    {
        $hasPayment = $payment !== null && $payment !== '';
        $hasOrder = is_string($order) && $order !== '';

        if (! $hasPayment && ! $hasOrder) {
            throw new InvalidArgumentException('Provide a payment ID or --order-id.');
        }

        if ($hasPayment && $hasOrder) {
            throw new InvalidArgumentException('Provide exactly one selector: a payment ID or --order-id.');
        }

        if (! is_string($destination) || $destination === '') {
            throw new InvalidArgumentException('The --to option is required.');
        }

        return new self(
            $hasPayment ? (string) IntegerInput::positive($payment, 'payment-id') : null,
            $hasOrder ? $order : null,
            CallbackTarget::fromString($destination),
        );
    }
}
