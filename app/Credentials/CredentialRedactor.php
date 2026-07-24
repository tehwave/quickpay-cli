<?php

namespace App\Credentials;

final class CredentialRedactor
{
    public static function redact(string $value, string $apiKey): string
    {
        if ($apiKey === '') {
            return $value;
        }

        return str_replace(
            [$apiKey, base64_encode(':'.$apiKey)],
            '[redacted]',
            $value,
        );
    }
}
