<?php

namespace App\Console\Terminal;

use InvalidArgumentException;

/**
 * Detects a real terminal independently from Symfony's interactive flag.
 *
 * Both checks are required before accepting confirmation: a command can be
 * marked interactive while stdin is still a pipe.
 */
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
