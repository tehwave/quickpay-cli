<?php

use App\Console\Confirmation\MutationConfirmation;
use App\Console\Terminal\StdinTerminalDetector;

it('accepts explicit non-interactive approval without prompting', function (): void {
    $terminal = new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return false;
        }
    };
    $prompted = false;

    expect((new MutationConfirmation($terminal))->approve(
        preapproved: true,
        interactive: false,
        ask: function () use (&$prompted): bool {
            $prompted = true;

            return false;
        },
        nonInteractiveMessage: 'requires --yes',
    ))->toBeTrue()->and($prompted)->toBeFalse();
});

it('refuses implicit approval without an interactive tty', function (): void {
    $terminal = new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return false;
        }
    };

    expect(fn (): bool => (new MutationConfirmation($terminal))->approve(
        preapproved: false,
        interactive: true,
        ask: fn (): bool => true,
        nonInteractiveMessage: 'requires --yes',
    ))->toThrow(InvalidArgumentException::class, 'requires --yes');
});
