<?php

namespace App\Quickpay\Payments;

use App\Quickpay\QuickpayResponse;

final readonly class PaymentResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public QuickpayResponse $response,
        public array $data,
    ) {}
}
