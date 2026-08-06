<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Parses raw API targets without allowing them to select another origin.
 */
final class RawApiPath
{
    /**
     * @return array{path: string, query: array<array-key, mixed>}
     */
    public static function parse(string $input): array
    {
        if ($input === ''
            || preg_match('/[\x00-\x1F\x7F\\\\#]/', $input) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $input) === 1) {
            throw self::invalid();
        }

        [$path, $queryString] = array_pad(explode('?', $input, 2), 2, '');

        if ($path === '') {
            throw self::invalid();
        }

        $decoded = $path;
        $maximumDecodings = strlen($path) + 1;

        // Validate every decoding layer so double-encoded traversal, schemes,
        // hosts, controls, and backslashes cannot become dangerous downstream.
        for ($attempt = 0; $attempt < $maximumDecodings; $attempt++) {
            self::validateDecodedPath($decoded);
            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        $query = [];

        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        return [
            'path' => '/'.ltrim($path, '/'),
            'query' => $query,
        ];
    }

    private static function validateDecodedPath(string $path): void
    {
        if ($path === ''
            || preg_match('/[\x00-\x1F\x7F\\\\#]/', $path) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
            || str_starts_with($path, '//')) {
            throw self::invalid();
        }

        $relative = ltrim($path, '/');

        if ($relative === ''
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/', $relative) === 1
            || str_contains($relative, '@')) {
            throw self::invalid();
        }

        $segments = explode('/', $relative);

        if (in_array('..', $segments, true) || self::looksLikeHost($segments[0])) {
            throw self::invalid();
        }
    }

    private static function looksLikeHost(string $segment): bool
    {
        if (strcasecmp($segment, 'localhost') === 0
            || filter_var($segment, FILTER_VALIDATE_IP) !== false
            || (str_starts_with($segment, '[') && str_ends_with($segment, ']'))) {
            return true;
        }

        return preg_match(
            '/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}\z/D',
            $segment,
        ) === 1;
    }

    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Raw API path must be a safe relative path under api.quickpay.net.');
    }
}
