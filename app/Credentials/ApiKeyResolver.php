<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;

final readonly class ApiKeyResolver
{
    public function __construct(
        private EnvironmentVariables $environment,
        private MerchantStore $store,
        private ProjectMerchantFile $project,
    ) {}

    public function resolve(?string $merchant = null): ?ApiKey
    {
        if ($merchant !== null) {
            MerchantName::validate($merchant);
        }
        $environmentKey = $this->environment->get('QUICKPAY_API_KEY');
        if ($environmentKey !== null && $merchant !== null) {
            throw new CredentialException('Unset QUICKPAY_API_KEY before selecting a saved merchant.');
        }
        $binding = $merchant === null ? $this->project->find() : null;
        if ($environmentKey !== null && $binding !== null) {
            throw new CredentialException('Unset QUICKPAY_API_KEY before using a project merchant binding.');
        }
        if ($environmentKey !== null) {
            return new ApiKey($environmentKey, 'environment', null, 'environment');
        }

        $config = $this->store->read();
        $source = $merchant !== null ? 'explicit' : ($binding !== null ? 'project' : 'default');
        $name = $merchant ?? $binding['merchant'] ?? $config['default_merchant'];
        if ($name === null) {
            return null;
        }
        if (! isset($config['merchants'][$name])) {
            throw new CredentialException('Selected merchant is not saved. Run quickpay merchants:list to see saved names.');
        }

        return new ApiKey($config['merchants'][$name]['api_key'], 'config file', $name, $source);
    }
}
