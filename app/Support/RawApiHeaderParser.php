<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Parses custom raw headers while preserving client-owned security headers.
 */
final class RawApiHeaderParser
{
    private const PROTECTED_HEADERS = [
        'authorization',
        'host',
        'accept-version',
    ];

    /**
     * @param  array<int, string>  $fields
     * @return array<string, string>
     */
    public static function parse(array $fields): array
    {
        $headers = [];

        foreach ($fields as $field) {
            $separator = strpos($field, ':');

            if ($separator === false) {
                throw new InvalidArgumentException('Each header must use a name:value format.');
            }

            $rawName = substr($field, 0, $separator);
            $rawValue = substr($field, $separator + 1);

            if (preg_match('/[\x00-\x1F\x7F]/', $rawName.$rawValue) === 1) {
                throw new InvalidArgumentException('Raw API header names and values must not contain invalid characters.');
            }

            $name = trim($rawName, ' ');
            $value = trim($rawValue, ' ');

            if (preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Raw API header names and values must not contain invalid characters.');
            }

            if (in_array(strtolower($name), self::PROTECTED_HEADERS, true)) {
                throw new InvalidArgumentException("The {$name} header cannot be overridden.");
            }

            $headers[$name] = $value;
        }

        return $headers;
    }
}
