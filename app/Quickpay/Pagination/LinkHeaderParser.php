<?php

namespace App\Quickpay\Pagination;

use InvalidArgumentException;
use ValueError;

/**
 * Extracts a validated Quickpay `rel="next"` pagination target.
 *
 * Link headers are remote input. Parsing relation tokens exactly and reducing
 * the URL to a relative target prevents suffix hosts, userinfo, ports, or
 * fragments from escaping the client-owned Quickpay origin.
 */
final class LinkHeaderParser
{
    public static function next(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        foreach (self::split($header, ',', trackAngles: true) as $link) {
            if (preg_match('/\A\s*<([^>]*)>\s*(.*)\z/D', $link, $matches) !== 1) {
                throw new InvalidArgumentException('Malformed Link header returned by Quickpay.');
            }

            $relation = null;

            foreach (self::split($matches[2], ';') as $parameter) {
                $parameter = trim($parameter);

                if ($parameter === '') {
                    continue;
                }

                $separator = strpos($parameter, '=');

                if ($separator === false) {
                    continue;
                }

                $name = trim(substr($parameter, 0, $separator));

                if (strcasecmp($name, 'rel') !== 0) {
                    continue;
                }

                $relation = self::parameterValue(substr($parameter, $separator + 1));
            }

            if ($relation !== null && in_array('next', preg_split('/\s+/', trim($relation)) ?: [], true)) {
                return self::validatedRelativeUrl($matches[1]);
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private static function split(string $value, string $delimiter, bool $trackAngles = false): array
    {
        $parts = [];
        $start = 0;
        $quoted = false;
        $escaped = false;
        $angleDepth = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quoted && $character === '\\' && ! $escaped) {
                $escaped = true;

                continue;
            }

            if ($character === '"' && ! $escaped) {
                $quoted = ! $quoted;
            } elseif ($trackAngles && ! $quoted && $character === '<') {
                $angleDepth++;
            } elseif ($trackAngles && ! $quoted && $character === '>') {
                $angleDepth--;

                if ($angleDepth < 0) {
                    throw new InvalidArgumentException('Malformed Link header returned by Quickpay.');
                }
            } elseif ($character === $delimiter && ! $quoted && $angleDepth === 0) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }

            $escaped = false;
        }

        if ($quoted || $angleDepth !== 0) {
            throw new InvalidArgumentException('Malformed Link header returned by Quickpay.');
        }

        $parts[] = substr($value, $start);

        return $parts;
    }

    private static function parameterValue(string $value): string
    {
        $value = trim($value);

        if (! str_starts_with($value, '"')) {
            return $value;
        }

        if (strlen($value) < 2 || ! str_ends_with($value, '"')) {
            throw new InvalidArgumentException('Malformed Link header returned by Quickpay.');
        }

        return stripcslashes(substr($value, 1, -1));
    }

    private static function validatedRelativeUrl(string $url): string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('Pagination link must use the Quickpay API origin.');
        }

        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            $parts = false;
        }

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.quickpay.net'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Pagination link must use the Quickpay API origin.');
        }

        $relative = $parts['path'] ?? '/';

        if (! str_starts_with($relative, '/')) {
            $relative = '/'.$relative;
        }

        if (array_key_exists('query', $parts)) {
            $relative .= '?'.$parts['query'];
        }

        return $relative;
    }
}
