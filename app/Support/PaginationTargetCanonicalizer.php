<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use ValueError;

/**
 * Produces a comparison key for pagination cycle detection.
 *
 * Query-key order is irrelevant, while repeated values, their order, and the
 * difference between `flag` and `flag=` are significant and stay preserved.
 */
final class PaginationTargetCanonicalizer
{
    /** @param array<string, mixed> $query */
    public static function fromQuery(string $path, array $query): string
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return self::canonical($path.($queryString === '' ? '' : '?'.$queryString));
    }

    public static function canonical(string $target): string
    {
        try {
            $parts = parse_url($target);
        } catch (ValueError) {
            $parts = false;
        }

        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            throw new InvalidArgumentException('Pagination target must be a relative URL.');
        }

        /** @var array<string, array{key: string, values: array<int, array{bool, string}>}> $groups */
        $groups = [];
        $query = $parts['query'] ?? '';

        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                $separator = strpos($pair, '=');
                $rawKey = $separator === false ? $pair : substr($pair, 0, $separator);
                $rawValue = $separator === false ? '' : substr($pair, $separator + 1);
                $decodedKey = urldecode($rawKey);
                // Base64 keeps arbitrary decoded bytes valid as array keys and
                // JSON strings without conflating distinct byte sequences.
                $groupKey = base64_encode($decodedKey);
                $groups[$groupKey] ??= ['key' => $groupKey, 'values' => []];
                $groups[$groupKey]['values'][] = [$separator !== false, base64_encode(urldecode($rawValue))];
            }
        }

        ksort($groups, SORT_STRING);

        try {
            return json_encode([
                'path' => base64_encode($parts['path'] ?? ''),
                'query' => array_values($groups),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('Pagination target could not be canonicalized.');
        }
    }
}
