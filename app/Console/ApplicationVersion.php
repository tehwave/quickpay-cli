<?php

namespace App\Console;

final class ApplicationVersion
{
    public function resolve(?string $packageVersion, string $gitVersion): string
    {
        if (is_string($packageVersion)
            && preg_match('/\Av?([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?)\z/D', $packageVersion, $matches) === 1) {
            return $matches[1];
        }

        return $gitVersion;
    }
}
