<?php

namespace App\Callbacks;

use App\Callbacks\Input\CallbackRequest;
use App\Callbacks\Resolution\PrivateKeyResolver;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Quickpay\QuickpayClient;
use Closure;
use Illuminate\Http\Client\Factory;

final readonly class WatchCallbacks
{
    public function __construct(
        private Factory $http,
        private CallbackWatcherFactory $watchers,
        private PrivateKeyResolver $privateKeys,
    ) {}

    /** @param Closure(string, array<string, int|string|null>): void $observer */
    public function execute(
        QuickpayClient $quickpay,
        string $apiKey,
        CallbackRequest $request,
        int $interval,
        Closure $observer,
    ): void {
        $this->watchers->make($quickpay, $this->http)->run(
            paymentId: $request->paymentId,
            orderId: $request->orderId,
            target: $request->target,
            apiKey: $apiKey,
            privateKey: $this->privateKeys->resolve($quickpay),
            interval: $interval,
            observer: $observer,
        );
    }
}
