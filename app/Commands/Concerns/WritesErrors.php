<?php

namespace App\Commands\Concerns;

trait WritesErrors
{
    protected function failure(string $message): int
    {
        $this->getOutput()->getErrorStyle()->error($message);

        return self::FAILURE;
    }
}
