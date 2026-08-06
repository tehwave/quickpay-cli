<?php

namespace App\Callbacks;

use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;
use UnexpectedValueException;

/**
 * Fetches the signing key from Quickpay's authenticated account endpoint.
 *
 * Keeping response validation here prevents commands from accidentally
 * rendering, persisting, or passing the complete credential response around.
 */
final class AccountPrivateKeyFetcher
{
    public function fetch(QuickpayClient $quickpay): string
    {
        $response = $quickpay->get('/account/private-key');

        if (! $response->successful()) {
            throw new QuickpayRequestException('Unable to retrieve the Quickpay account private key.');
        }

        $key = is_array($response->json) ? ($response->json['private_key'] ?? null) : null;

        if (! is_string($key) || trim($key) === '') {
            throw new UnexpectedValueException('Quickpay returned an invalid account private key response.');
        }

        return $key;
    }
}
