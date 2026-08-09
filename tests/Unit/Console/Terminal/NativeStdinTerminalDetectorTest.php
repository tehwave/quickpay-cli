<?php

use App\Console\Terminal\NativeStdinTerminalDetector;

it('recognizes a non-tty stdin stream', function () {
    $stream = fopen('php://memory', 'r');

    try {
        expect((new NativeStdinTerminalDetector($stream))->isTty())->toBeFalse();
    } finally {
        fclose($stream);
    }
});
