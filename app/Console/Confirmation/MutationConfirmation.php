<?php

namespace App\Console\Confirmation;

use App\Console\Terminal\StdinTerminalDetector;
use Closure;
use InvalidArgumentException;

final readonly class MutationConfirmation
{
    public function __construct(private StdinTerminalDetector $terminal) {}

    /** @param Closure(): bool $ask */
    public function approve(
        bool $preapproved,
        bool $interactive,
        Closure $ask,
        string $nonInteractiveMessage,
    ): bool {
        if ($preapproved) {
            return true;
        }

        if (! $interactive || ! $this->terminal->isTty()) {
            throw new InvalidArgumentException($nonInteractiveMessage);
        }

        return $ask();
    }
}
