<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;

final readonly class HomeDirectory
{
    public function __construct(
        private EnvironmentVariables $environment,
        private ?string $override = null,
    ) {}

    public function path(): string
    {
        if ($this->override !== null && trim($this->override) !== '') {
            return $this->override;
        }

        foreach (['HOME', 'USERPROFILE'] as $variable) {
            $value = $this->environment->get($variable);

            if ($value !== null) {
                return $value;
            }
        }

        throw new CredentialException('Unable to locate the home directory for Quickpay credentials.');
    }
}
