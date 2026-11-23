<?php

namespace App\Callbacks;

use App\Callbacks\Input\CallbackRequest;
use App\Callbacks\Resolution\PrivateKeyResolver;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Callbacks\Watching\CallbackWatchObserver;
use App\Callbacks\Watching\CallbackWatchRequest;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;

final readonly class WatchCallbacks
{
    public function __construct(
        private Factory $http,
        private CallbackWatcherFactory $watchers,
        private PrivateKeyResolver $privateKeys,
    ) {}

    public function execute(
        QuickpayClient $quickpay,
        string $apiKey,
        CallbackRequest $request,
        int $interval,
        int $deliveryAttempts,
        CallbackWatchObserver $observer,
    ): void {
        $this->watchers->make($quickpay, $this->http)->run(
            new CallbackWatchRequest(
                paymentId: $request->paymentId,
                orderId: $request->orderId,
                target: $request->target,
                apiKey: $apiKey,
                privateKey: $this->privateKeys->resolve($quickpay),
                interval: $interval,
                deliveryAttempts: $deliveryAttempts,
            ),
            $observer,
        );
    }
}
