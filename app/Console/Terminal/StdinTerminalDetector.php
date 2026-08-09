<?php

namespace App\Console\Terminal;

interface StdinTerminalDetector
{
    public function isTty(): bool;
}
