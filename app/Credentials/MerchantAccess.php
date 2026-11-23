<?php

namespace App\Credentials;

/**
 * Whether a saved merchant is read-only.
 *
 * GET is a read and always allowed. Every other method is a write, so an
 * unknown or future method is blocked for a read-only merchant instead of
 * slipping through.
 */
final readonly class MerchantAccess
{
    public function __construct(
        public bool $readOnly,
    ) {}

    public static function full(): self
    {
        return new self(readOnly: false);
    }

    public function allows(string $method): bool
    {
        return strtoupper($method) === 'GET' || ! $this->readOnly;
    }

    public function label(): string
    {
        return $this->readOnly ? 'read-only' : 'read and write';
    }
}
