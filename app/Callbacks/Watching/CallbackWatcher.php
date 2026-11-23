<?php

namespace App\Callbacks\Watching;

/**
 * Runs one foreground callback watch session.
 *
 * The command depends on this boundary so presentation can be tested without
 * introducing production-only loop limits or sleeping in the test process.
 */
interface CallbackWatcher
{
    public function run(CallbackWatchRequest $request, CallbackWatchObserver $observer): void;
}
