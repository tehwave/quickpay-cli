<?php

namespace App\Callbacks\Delivery;

enum CallbackDeliveryFailure
{
    case HttpResponse;
    case Network;
    case RedirectRejected;
}
