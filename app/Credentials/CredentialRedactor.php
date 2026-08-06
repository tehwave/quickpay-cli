<?php

namespace App\Credentials;

/**
 * Removes credentials from literal, Basic-auth, and URL-encoded output.
 *
 * Matching is deliberately byte-oriented: API responses are untrusted and may
 * contain mixed or partial encodings that cannot safely be normalized first.
 */
final class CredentialRedactor
{
    public static function redact(string $value, string $apiKey): string
    {
        if ($apiKey === '') {
            return $value;
        }

        $redacted = self::replaceSensitiveMatches($value, $apiKey, self::replacement($apiKey));

        // Replacements can join surrounding bytes into a fresh match. Repeat
        // until the result is demonstrably safe instead of assuming one pass.
        while (self::containsSensitiveValue($redacted, $apiKey)) {
            $previous = $redacted;
            $redacted = self::replaceSensitiveMatches($redacted, $apiKey, '');

            if ($redacted === $previous) {
                // An empty result is safer than returning an unredactable key.
                return '';
            }
        }

        return $redacted;
    }

    public static function containsSensitiveValue(string $value, string $apiKey): bool
    {
        return $apiKey !== '' && self::sensitiveMatches($value, $apiKey) !== [];
    }

    private static function replacement(string $apiKey): string
    {
        // Even a placeholder can collide when a credential is unusually short.
        foreach (['[redacted]', '[credential removed]'] as $candidate) {
            if (! self::containsSensitiveValue($candidate, $apiKey)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function replaceSensitiveMatches(string $value, string $apiKey, string $replacement): string
    {
        $matches = self::sensitiveMatches($value, $apiKey);

        if ($matches === []) {
            return $value;
        }

        $redacted = '';
        $offset = 0;

        foreach ($matches as [$start, $end]) {
            $redacted .= substr($value, $offset, $start - $offset).$replacement;
            $offset = $end;
        }

        return $redacted.substr($value, $offset);
    }

    /** @return array<int, array{0: int, 1: int}> */
    private static function sensitiveMatches(string $value, string $apiKey): array
    {
        $credentials = [$apiKey, base64_encode(':'.$apiKey)];
        $matches = self::literalMatches($value, $credentials);

        // Check RFC 3986 percent encoding and form encoding. Tracking source
        // spans lets us remove encoded bytes without rewriting unrelated text.
        foreach ([false, true] as $plusAsSpace) {
            [$normalized, $spans] = self::normalizePercentEncoding($value, $plusAsSpace);

            foreach ($credentials as $credential) {
                $searchOffset = 0;

                while (($match = strpos($normalized, $credential, $searchOffset)) !== false) {
                    $lastByte = $match + strlen($credential) - 1;
                    $matches[] = [$spans[$match][0], $spans[$lastByte][1]];
                    $searchOffset = $match + 1;
                }
            }
        }

        return self::mergeMatches($matches);
    }

    /**
     * @param  array<int, string>  $credentials
     * @return array<int, array{0: int, 1: int}>
     */
    private static function literalMatches(string $value, array $credentials): array
    {
        $matches = [];

        foreach ($credentials as $credential) {
            $searchOffset = 0;

            while (($match = strpos($value, $credential, $searchOffset)) !== false) {
                $matches[] = [$match, $match + strlen($credential)];
                $searchOffset = $match + 1;
            }
        }

        return $matches;
    }

    /**
     * @return array{0: string, 1: array<int, array{0: int, 1: int}>}
     */
    private static function normalizePercentEncoding(string $value, bool $plusAsSpace): array
    {
        $normalized = '';
        $spans = [];
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            if ($value[$index] === '%'
                && $index + 2 < $length
                && ctype_xdigit($value[$index + 1].$value[$index + 2])) {
                $byte = hex2bin($value[$index + 1].$value[$index + 2]);

                if ($byte !== false) {
                    $normalized .= $byte;
                    $spans[] = [$index, $index + 3];
                    $index += 2;

                    continue;
                }
            }

            $normalized .= $plusAsSpace && $value[$index] === '+' ? ' ' : $value[$index];
            $spans[] = [$index, $index + 1];
        }

        return [$normalized, $spans];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $matches
     * @return array<int, array{0: int, 1: int}>
     */
    private static function mergeMatches(array $matches): array
    {
        usort($matches, fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $right[1] <=> $left[1]);
        $merged = [];

        foreach ($matches as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
