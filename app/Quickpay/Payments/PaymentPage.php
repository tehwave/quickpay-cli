<?php

namespace App\Quickpay\Payments;

use App\Quickpay\QuickpayResponse;

final readonly class PaymentPage
{
    /** @param array<int, mixed> $payments */
    public function __construct(
        public QuickpayResponse $response,
        public array $payments,
    ) {}
}
