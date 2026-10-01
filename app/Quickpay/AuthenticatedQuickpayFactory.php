<?php

namespace App\Quickpay;

use App\Credentials\ApiKey;
use App\Credentials\ApiKeyResolver;
use App\Quickpay\Payments\PaymentApi;
use Illuminate\Http\Client\Factory;

final readonly class AuthenticatedQuickpayFactory
{
    public function __construct(
        private Factory $http,
        private ApiKeyResolver $credentials,
    ) {}

    public function resolve(?string $merchant = null): ?AuthenticatedQuickpay
    {
        $apiKey = $this->credentials->resolve($merchant);

        return $apiKey === null ? null : $this->from($apiKey);
    }

    public function from(ApiKey $apiKey): AuthenticatedQuickpay
    {
        $client = new QuickpayClient($this->http, $apiKey->value());

        return new AuthenticatedQuickpay(
            $client,
            $apiKey,
            new PaymentApi($client),
        );
    }
}
