<?php

namespace App\Console;

use Illuminate\Console\Command;

abstract class BaseCommand extends Command
{
    protected function failure(string $message): int
    {
        $this->getOutput()->getErrorStyle()->error($message);

        return self::FAILURE;
    }
}
