<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;

/**
 * Assembles the session-specific watcher around one credential-scoped client.
 */
class CallbackWatcherFactory
{
    public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
    {
        return new CallbackWatchRunner(
            locator: new PaymentLocator($quickpay),
            envelopes: new CallbackEnvelopeFactory,
            forwarder: new CallbackForwarder($http),
        );
    }
}
