<?php

namespace App\Credentials;

final class EnvironmentVariables
{
    public function get(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
