<?php

namespace App\Credentials;

final class CredentialRedactor
{
    public static function redact(string $value, string $apiKey): string
    {
        $sensitiveValues = self::sensitiveValues($apiKey);

        if ($sensitiveValues === []) {
            return $value;
        }

        return str_replace($sensitiveValues, self::replacement($sensitiveValues), $value);
    }

    public static function containsSensitiveValue(string $value, string $apiKey): bool
    {
        return self::containsAny($value, self::sensitiveValues($apiKey));
    }

    /** @return array<int, string> */
    private static function sensitiveValues(string $apiKey): array
    {
        if ($apiKey === '') {
            return [];
        }

        $values = array_values(array_unique([
            $apiKey,
            base64_encode(':'.$apiKey),
        ]));

        usort($values, function (string $left, string $right): int {
            $lengthComparison = strlen($right) <=> strlen($left);

            return $lengthComparison !== 0 ? $lengthComparison : strcmp($left, $right);
        });

        return $values;
    }

    /** @param array<int, string> $sensitiveValues */
    private static function replacement(array $sensitiveValues): string
    {
        foreach (['[redacted]', '[credential removed]'] as $candidate) {
            if (! self::containsAny($candidate, $sensitiveValues)) {
                return $candidate;
            }
        }

        return '';
    }

    /** @param array<int, string> $sensitiveValues */
    private static function containsAny(string $value, array $sensitiveValues): bool
    {
        foreach ($sensitiveValues as $sensitiveValue) {
            if ($sensitiveValue !== '' && str_contains($value, $sensitiveValue)) {
                return true;
            }
        }

        return false;
    }
}
