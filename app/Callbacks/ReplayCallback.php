<?php

namespace App\Callbacks;

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Input\CallbackRequest;
use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Resolution\PrivateKeyResolver;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

final readonly class ReplayCallback
{
    public function __construct(
        private Factory $http,
        private PrivateKeyResolver $privateKeys,
    ) {}

    public function execute(QuickpayClient $quickpay, string $apiKey, CallbackRequest $request): ReplayResult
    {
        $locator = new PaymentLocator($quickpay);
        $payment = $request->paymentId !== null
            ? $locator->byId($request->paymentId)
            : $locator->byOrderId($request->orderId ?? '');

        if ($payment === null) {
            throw new InvalidArgumentException("No payment found for order ID {$request->orderId}.");
        }

        $envelope = (new CallbackEnvelopeFactory)->make(
            $payment,
            $apiKey,
            $this->privateKeys->resolve($quickpay),
        );
        $delivery = (new CallbackForwarder($this->http))->deliver($request->target, $envelope);

        return new ReplayResult($envelope, $delivery);
    }
}
