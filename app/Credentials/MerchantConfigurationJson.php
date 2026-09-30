<?php

namespace App\Credentials;

use JsonException;

final class MerchantConfigurationJson
{
    public static function decode(string $contents): mixed
    {
        $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);

        // Native decoding discards duplicate fields. Inspect complete JSON string
        // tokens per object so escaped names collide, but quoted values do not.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}:]/s', $contents, $matches);
        $tokens = $matches[0];
        $objects = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{') {
                $objects[] = [];
            } elseif ($token === '}') {
                array_pop($objects);
            } elseif ($token[0] === '"' && ($tokens[$index + 1] ?? null) === ':') {
                $name = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $scope = array_key_last($objects);
                if (! is_string($name) || $scope === null || isset($objects[$scope][$name])) {
                    throw new JsonException('Ambiguous configuration contains duplicate JSON properties.');
                }
                $objects[$scope][$name] = true;
            }
        }

        return $decoded;
    }
}
