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

        $redacted = str_replace($sensitiveValues, self::replacement($sensitiveValues), $value);

        while (self::containsAny($redacted, $sensitiveValues)) {
            $previousLength = strlen($redacted);
            $redacted = str_replace($sensitiveValues, '', $redacted);

            if (strlen($redacted) >= $previousLength) {
                return '';
            }
        }

        return $redacted;
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

        $values = [];

        foreach ([
            $apiKey,
            base64_encode(':'.$apiKey),
        ] as $credential) {
            foreach ([
                $credential,
                rawurlencode($credential),
                urlencode($credential),
                self::percentEncodeEveryByte($credential),
            ] as $representation) {
                $values[] = $representation;
                $values[] = self::lowercasePercentEscapes($representation);
            }
        }

        $values = array_values(array_unique($values));

        usort($values, function (string $left, string $right): int {
            $lengthComparison = strlen($right) <=> strlen($left);

            return $lengthComparison !== 0 ? $lengthComparison : strcmp($left, $right);
        });

        return $values;
    }

    private static function lowercasePercentEscapes(string $value): string
    {
        return preg_replace_callback(
            '/%[0-9A-F]{2}/',
            fn (array $match): string => strtolower($match[0]),
            $value,
        ) ?? $value;
    }

    private static function percentEncodeEveryByte(string $value): string
    {
        $encoded = '';

        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $encoded .= sprintf('%%%02X', ord($value[$index]));
        }

        return $encoded;
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
