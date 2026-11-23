<?php

namespace App\Credentials;

/**
 * Which Quickpay requests a saved merchant may send.
 *
 * GET is a read. Every other method is a write, so an unknown or future
 * method is blocked for a read-only merchant instead of slipping through.
 */
final readonly class MerchantAccess
{
    public function __construct(
        public bool $read,
        public bool $write,
    ) {}

    public static function full(): self
    {
        return new self(true, true);
    }

    public function allows(string $method): bool
    {
        return strtoupper($method) === 'GET' ? $this->read : $this->write;
    }

    public function isFull(): bool
    {
        return $this->read && $this->write;
    }

    /**
     * The request types a change would allow again. Allowing what is already
     * allowed lifts nothing, so it never needs a person to confirm it.
     *
     * @return list<string>
     */
    public function lifts(?bool $read, ?bool $write): array
    {
        return array_keys(array_filter([
            'read' => $read === true && ! $this->read,
            'write' => $write === true && ! $this->write,
        ]));
    }

    public function label(): string
    {
        return match (true) {
            $this->read && $this->write => 'read and write',
            $this->read => 'read-only',
            $this->write => 'write-only',
            default => 'reads and writes blocked',
        };
    }
}
