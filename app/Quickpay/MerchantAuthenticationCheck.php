<?php

namespace App\Quickpay;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\ApiKey;
use App\Quickpay\Exceptions\QuickpayRequestException;
use UnexpectedValueException;

final readonly class MerchantAuthenticationCheck
{
    public function __construct(private AuthenticatedQuickpayFactory $quickpay) {}

    public function check(ApiKey $apiKey): void
    {
        $response = $this->quickpay->from($apiKey)->client->get('/ping');
        if (! $response->successful()) {
            throw new QuickpayRequestException('Authentication check failed: '.ResponseBodySanitizer::terminalLine($response->errorSummary(), $apiKey->value()));
        }
        if (! is_array($response->json) || ($response->json['scope'] ?? null) !== 'merchant') {
            throw new UnexpectedValueException('Quickpay authentication check did not return a valid merchant scope.');
        }
    }
}
