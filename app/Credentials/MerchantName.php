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
}
