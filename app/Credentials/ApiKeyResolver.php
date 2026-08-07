<?php

namespace App\Credentials;

final readonly class ApiKeyResolver
{
    public function __construct(
        private EnvironmentVariables $environment,
        private CredentialFile $file,
    ) {}

    public function resolve(): ?ApiKey
    {
        $environmentKey = $this->environment->get('QUICKPAY_API_KEY');

        if ($environmentKey !== null) {
            return new ApiKey($environmentKey, 'environment');
        }

        $storedKey = $this->file->read();

        return $storedKey === null ? null : new ApiKey($storedKey, 'config file');
    }
}
