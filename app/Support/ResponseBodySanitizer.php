<?php

namespace App\Support;

use App\Credentials\CredentialRedactor;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class ResponseBodySanitizer
{
    public static function json(string $body, string $apiKey): string
    {
        try {
            $decoded = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Quickpay returned a successful response without valid JSON.');
        }

        $changed = false;
        $safe = self::redactJsonValue($decoded, $apiKey, $changed);
        $rawExposesCredential = CredentialRedactor::containsSensitiveValue($body, $apiKey);

        if (! $changed && ! $rawExposesCredential) {
            return $body;
        }

        $encoded = self::encodeJson($safe);

        if (CredentialRedactor::containsSensitiveValue($encoded, $apiKey)) {
            $encoded = self::safeFallbackJson($apiKey);
        }

        if (CredentialRedactor::containsSensitiveValue($encoded, $apiKey)) {
            throw new InvalidArgumentException('Quickpay returned JSON that could not be rendered without exposing credentials.');
        }

        return $encoded;
    }

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('Quickpay returned JSON that could not be rendered safely.');
        }
    }

    private static function safeFallbackJson(string $apiKey): string
    {
        foreach ([null, false, 0, [], new stdClass] as $fallback) {
            $encoded = self::encodeJson($fallback);

            if (! CredentialRedactor::containsSensitiveValue($encoded, $apiKey)) {
                return $encoded;
            }
        }

        throw new InvalidArgumentException('Quickpay returned JSON that could not be rendered without exposing credentials.');
    }

    public static function terminalText(string $body, string $apiKey): string
    {
        $body = CredentialRedactor::redact($body, $apiKey);
        $safe = '';
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($body[$index]);

            if ($byte === 0x0D && ($body[$index + 1] ?? null) === "\n") {
                $safe .= "\r\n";
                $index++;

                continue;
            }

            if ($byte === 0x0A || ($byte >= 0x20 && $byte <= 0x7E)) {
                $safe .= $body[$index];

                continue;
            }

            if ($byte < 0x80) {
                $safe .= self::visibleByte($byte);

                continue;
            }

            $sequenceLength = self::utf8SequenceLength($body, $index);

            if ($sequenceLength === 0) {
                $safe .= self::visibleByte($byte);

                continue;
            }

            $sequence = substr($body, $index, $sequenceLength);

            if ($sequenceLength === 2 && $byte === 0xC2 && ord($sequence[1]) <= 0x9F) {
                $safe .= self::visibleBytes($sequence);
            } else {
                $safe .= $sequence;
            }

            $index += $sequenceLength - 1;
        }

        return $safe;
    }

    public static function terminalLine(string $body, string $apiKey): string
    {
        return str_replace(
            ["\r\n", "\n"],
            ['\\x0D\\x0A', '\\x0A'],
            self::terminalText($body, $apiKey),
        );
    }

    private static function redactJsonValue(mixed $value, string $apiKey, bool &$changed): mixed
    {
        if ($value instanceof stdClass) {
            $safe = new stdClass;

            foreach (get_object_vars($value) as $key => $item) {
                $changed = $changed || CredentialRedactor::containsSensitiveValue($key, $apiKey);
                $safeKey = CredentialRedactor::redact($key, $apiKey);
                $safe->{$safeKey} = self::redactJsonValue($item, $apiKey, $changed);
            }

            return $safe;
        }

        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $item) {
                $safe[$key] = self::redactJsonValue($item, $apiKey, $changed);
            }

            return $safe;
        }

        if (is_string($value)) {
            $changed = $changed || CredentialRedactor::containsSensitiveValue($value, $apiKey);

            return CredentialRedactor::redact($value, $apiKey);
        }

        if (is_int($value) || is_float($value)) {
            $rendered = self::encodeJson($value);
            if (CredentialRedactor::containsSensitiveValue($rendered, $apiKey)) {
                $changed = true;

                return CredentialRedactor::redact($rendered, $apiKey);
            }

            return $value;
        }

        if (is_bool($value)) {
            $rendered = $value ? 'true' : 'false';
            if (CredentialRedactor::containsSensitiveValue($rendered, $apiKey)) {
                $changed = true;

                return CredentialRedactor::redact($rendered, $apiKey);
            }
        }

        if ($value === null) {
            if (CredentialRedactor::containsSensitiveValue('null', $apiKey)) {
                $changed = true;

                return CredentialRedactor::redact('null', $apiKey);
            }
        }

        return $value;
    }

    private static function utf8SequenceLength(string $value, int $offset): int
    {
        $remaining = substr($value, $offset, 4);
        $patterns = [
            2 => '/\A[\xC2-\xDF][\x80-\xBF]/',
            3 => '/\A(?:\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF])/',
            4 => '/\A(?:\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})/',
        ];

        foreach ($patterns as $length => $pattern) {
            if (preg_match($pattern, $remaining) === 1) {
                return $length;
            }
        }

        return 0;
    }

    private static function visibleBytes(string $bytes): string
    {
        $safe = '';

        for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
            $safe .= self::visibleByte(ord($bytes[$index]));
        }

        return $safe;
    }

    private static function visibleByte(int $byte): string
    {
        return sprintf('\\x%02X', $byte);
    }
}
