<?php

namespace App\Quickpay;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\ApiKey;
use App\Quickpay\Payments\PaymentApi;

final readonly class AuthenticatedQuickpay
{
    public function __construct(
        public QuickpayClient $client,
        public ApiKey $apiKey,
        public PaymentApi $payments,
    ) {}

    public function safeLine(string $value): string
    {
        return ResponseBodySanitizer::terminalLine($value, $this->apiKey->value());
    }
}
