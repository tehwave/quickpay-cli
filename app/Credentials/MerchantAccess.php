<?php

namespace App\Credentials;

/**
 * Whether a saved merchant may send write requests.
 *
 * GET is a read and always allowed. Every other method is a write, so an
 * unknown or future method is blocked for a read-only merchant instead of
 * slipping through.
 */
final readonly class MerchantAccess
{
    public function __construct(
        public bool $write,
    ) {}

    public static function full(): self
    {
        return new self(true);
    }

    public function allows(string $method): bool
    {
        return strtoupper($method) === 'GET' || $this->write;
    }

    public function label(): string
    {
        return $this->write ? 'read and write' : 'read-only';
    }
}
