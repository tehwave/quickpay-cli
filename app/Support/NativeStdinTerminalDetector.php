<?php

namespace App\Support;

use InvalidArgumentException;

final class NativeStdinTerminalDetector implements StdinTerminalDetector
{
    /** @var resource */
    private mixed $stdin;

    public function __construct(mixed $stdin = null)
    {
        $stdin ??= STDIN;

        if (! is_resource($stdin)) {
            throw new InvalidArgumentException('STDIN must be a stream resource.');
        }

        $this->stdin = $stdin;
    }

    public function isTty(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($this->stdin);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty($this->stdin);
        }

        return false;
    }
}
