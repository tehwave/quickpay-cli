<?php

namespace App\Callbacks;

use App\Quickpay\QuickpayClient;

/**
 * Resolves the private key used for callback signatures.
 *
 * The environment override makes offline and least-privilege workflows
 * possible without silently creating another credential store on disk.
 */
final readonly class PrivateKeyResolver
{
    public function __construct(private AccountPrivateKeyFetcher $fetcher) {}

    public function resolve(QuickpayClient $quickpay): string
    {
        $environment = getenv('QUICKPAY_PRIVATE_KEY');

        if (is_string($environment) && trim($environment) !== '') {
            return $environment;
        }

        return $this->fetcher->fetch($quickpay);
    }
}
