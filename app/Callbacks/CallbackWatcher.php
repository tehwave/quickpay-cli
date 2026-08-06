<?php

namespace App\Callbacks;

use Closure;

/**
 * Runs one foreground callback watch session.
 *
 * The command depends on this boundary so presentation can be tested without
 * introducing production-only loop limits or sleeping in the test process.
 */
interface CallbackWatcher
{
    /**
     * @param  Closure(string, array<string, int|string|null>): void  $observer
     */
    public function run(
        ?string $paymentId,
        ?string $orderId,
        CallbackTarget $target,
        string $apiKey,
        string $privateKey,
        int $interval,
        Closure $observer,
    ): void;
}
