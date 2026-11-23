<?php

namespace App\Callbacks\Resolution;

use App\Credentials\ApiKey;
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

    /**
     * Explains an environment private key used with a saved merchant.
     *
     * The override is not tied to any merchant, so it may sign callbacks with
     * another merchant's key. An environment API key is paired with it on
     * purpose and gets no warning.
     */
    public function overrideWarning(ApiKey $apiKey): ?string
    {
        $merchant = $apiKey->merchant();
        if ($merchant === null || $this->environment->get('QUICKPAY_PRIVATE_KEY') === null) {
            return null;
        }

        return "Warning: QUICKPAY_PRIVATE_KEY is set, so callbacks are signed with it instead of the account private key of {$merchant}. Unset it if it belongs to another merchant.";
    }
}
