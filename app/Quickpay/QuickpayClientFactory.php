<?php

namespace App\Quickpay;

use Illuminate\Http\Client\Factory;

final readonly class QuickpayClientFactory
{
    public function __construct(private Factory $http) {}

    public function make(string $apiKey): QuickpayClient
    {
        return new QuickpayClient($this->http, $apiKey);
    }
}
