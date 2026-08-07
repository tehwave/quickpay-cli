<?php

namespace App\Console\Output;

use InvalidArgumentException;
use JsonException;

final class JsonOutput
{
    public static function value(mixed $value, string $apiKey): string
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Quickpay returned JSON that could not be rendered safely.');
        }

        return ResponseBodySanitizer::json($json, $apiKey);
    }
}
