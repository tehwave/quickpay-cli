<?php

namespace App\Credentials;

use App\Credentials\Redaction\CredentialRedactor;
use InvalidArgumentException;

final readonly class ApiKey
{
    public function __construct(
        private string $value,
        private string $source,
        private ?string $merchant = null,
        private ?string $selectionSource = null,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Quickpay API key must not be empty.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function merchant(): ?string
    {
        return $this->merchant;
    }

    public function selectionSource(): ?string
    {
        return $this->selectionSource;
    }

    public function redact(string $value): string
    {
        return CredentialRedactor::redact($value, $this->value);
    }

    public function appearsIn(string $value): bool
    {
        return CredentialRedactor::containsSensitiveValue($value, $this->value);
    }
}
