<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Parses repeatable `key=value` options with explicit bracket nesting.
 *
 * Conflicting scalar and object shapes are rejected rather than silently
 * changing an earlier value's type.
 */
final class KeyValueParser
{
    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public static function parse(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $separator = strpos($field, '=');

            if ($separator === false || trim(substr($field, 0, $separator)) === '') {
                throw new InvalidArgumentException('Each field must use a non-empty key=value format.');
            }

            $key = substr($field, 0, $separator);
            $value = substr($field, $separator + 1);
            $segments = self::segments($key);
            $cursor = &$result;

            foreach ($segments as $index => $segment) {
                $last = $index === array_key_last($segments);

                if ($last) {
                    self::setScalar($cursor, $segment, $value, $key);

                    continue;
                }

                if (! array_key_exists($segment, $cursor)) {
                    $cursor[$segment] = [];
                } elseif (! is_array($cursor[$segment])) {
                    throw new InvalidArgumentException("Field '{$key}' conflicts with an existing scalar value.");
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $result;
    }

    /** @param array<string|int, mixed> $target */
    private static function setScalar(array &$target, string $segment, string $value, string $key): void
    {
        if (array_key_exists($segment, $target) && is_array($target[$segment])) {
            throw new InvalidArgumentException("Field '{$key}' conflicts with an existing nested value.");
        }

        $target[$segment] = $value;
    }

    /** @return array<int, string> */
    private static function segments(string $key): array
    {
        if (preg_match('/\A([^\[\]]+)((?:\[[^\[\]]+\])*)\z/D', $key, $matches) !== 1) {
            throw new InvalidArgumentException("Malformed field key '{$key}'.");
        }

        $segments = [$matches[1]];

        if ($matches[2] !== '') {
            preg_match_all('/\[([^\[\]]+)\]/', $matches[2], $nested);
            array_push($segments, ...$nested[1]);
        }

        return $segments;
    }
}
