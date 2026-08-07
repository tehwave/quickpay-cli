<?php

namespace App\Callbacks;

use App\Callbacks\Delivery\CallbackDelivery;
use App\Callbacks\Signing\CallbackEnvelope;

final readonly class ReplayResult
{
    public function __construct(
        public CallbackEnvelope $envelope,
        public CallbackDelivery $delivery,
    ) {}
}
