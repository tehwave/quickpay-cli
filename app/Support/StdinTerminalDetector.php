<?php

namespace App\Support;

interface StdinTerminalDetector
{
    public function isTty(): bool;
}
