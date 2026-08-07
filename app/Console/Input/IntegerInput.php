<?php

namespace App\Console\Input;

use InvalidArgumentException;

final class IntegerInput
{
    public static function positive(mixed $value, string $name): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        $value = (string) $value;
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1 || $integer === false) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return $integer;
    }

    public static function nonNegative(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ((! is_string($value) && ! is_int($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value) !== 1
            || $integer === false) {
            throw new InvalidArgumentException("{$name} must be a non-negative integer.");
        }

        return $integer;
    }

    public static function nonNegativeNumber(mixed $value, string $name): int|float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || preg_match('/\A(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\z/D', (string) $value) !== 1
            || ! is_numeric($value)) {
            throw new InvalidArgumentException("{$name} must be a non-negative number.");
        }

        $number = str_contains((string) $value, '.') ? (float) $value : (int) $value;

        if (! is_finite((float) $number) || $number < 0) {
            throw new InvalidArgumentException("{$name} must be a non-negative number.");
        }

        return $number;
    }
}
