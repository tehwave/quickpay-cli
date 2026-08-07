<?php

namespace App\Callbacks\Resolution;

use App\Credentials\EnvironmentVariables;
use App\Quickpay\QuickpayClient;

/**
 * Resolves the private key used for callback signatures.
 *
 * The environment override makes offline and least-privilege workflows
 * possible without silently creating another credential store on disk.
 */
final readonly class PrivateKeyResolver
{
    public function __construct(
        private AccountPrivateKeyFetcher $fetcher,
        private EnvironmentVariables $environment,
    ) {}

    public function resolve(QuickpayClient $quickpay): string
    {
        $environment = $this->environment->get('QUICKPAY_PRIVATE_KEY');

        if ($environment !== null) {
            return $environment;
        }

        return $this->fetcher->fetch($quickpay);
    }
}
