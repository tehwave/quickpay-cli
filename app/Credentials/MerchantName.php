<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;

final class MerchantName
{
    public static function validate(string $name): string
    {
        if (strlen($name) > 64 || preg_match('/\A[a-z][a-z0-9]*(?:[ -][a-z0-9]+)*\z/iD', $name) !== 1) {
            throw new CredentialException('Merchant names must start with a letter and use 1-64 ASCII letters, digits, or single separating spaces or hyphens.');
        }

        return $name;
    }

    /**
     * Returns a saved name that differs from $name only in capitalization.
     *
     * Names match exactly, so "Shop" and "shop" would be two merchants that a
     * mistyped --merchant silently confuses. A new name must not shadow one.
     *
     * @param  array<int, string>  $saved
     */
    public static function caseVariant(string $name, array $saved): ?string
    {
        foreach ($saved as $existing) {
            if ($existing !== $name && strcasecmp($existing, $name) === 0) {
                return $existing;
            }
        }

        return null;
    }
}
