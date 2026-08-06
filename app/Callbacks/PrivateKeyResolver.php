<?php

namespace App\Callbacks;

use Closure;
use UnexpectedValueException;

/**
 * Resolves and session-caches the private key used for callback signatures.
 *
 * The environment override makes offline and least-privilege workflows
 * possible. The fetched key is held only in memory and never exposed by this
 * object, which avoids silently creating another credential store on disk.
 */
final class PrivateKeyResolver
{
    private ?string $resolved = null;

    private readonly Closure $fetch;

    public function __construct(callable $fetch)
    {
        $this->fetch = Closure::fromCallable($fetch);
    }

    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $environment = getenv('QUICKPAY_PRIVATE_KEY');
        $value = is_string($environment) && trim($environment) !== ''
            ? $environment
            : ($this->fetch)();

        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException('Quickpay did not provide a usable account private key.');
        }

        return $this->resolved = $value;
    }
}
