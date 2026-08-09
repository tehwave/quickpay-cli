<?php

namespace App\Quickpay\Payments;

use Closure;

final readonly class PaymentMutation
{
    public function __construct(private PaymentApi $payments) {}

    /**
     * @param  array<string, int|float>  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  Closure(array<string, mixed>): bool  $confirm
     */
    public function execute(
        int $id,
        string $operation,
        array $body,
        array $query,
        array $headers,
        Closure $confirm,
    ): ?PaymentResult {
        $payment = $this->payments->get(
            $id,
            invalid: 'Quickpay returned invalid payment context.',
        );

        if (! $confirm($payment->data)) {
            return null;
        }

        return $this->payments->mutate($id, $operation, $query, $body, $headers);
    }
}
