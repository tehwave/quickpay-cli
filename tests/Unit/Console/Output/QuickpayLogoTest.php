<?php

use App\Console\Output\QuickpayLogo;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\AnsiColorMode;
use Symfony\Component\Console\Terminal;

it('renders the ASCII logo in the Quickpay brand color', function () {
    Terminal::setColorMode(AnsiColorMode::Ansi24);

    try {
        $rendered = (new OutputFormatter(true))->format(QuickpayLogo::styled());
    } finally {
        Terminal::setColorMode(null);
    }

    expect($rendered)->toBe("\e[38;2;252;17;84;1m".QuickpayLogo::ASCII."\e[39;22m");
});
