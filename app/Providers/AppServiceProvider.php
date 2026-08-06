<?php

namespace App\Providers;

use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClientFactory;
use App\Support\NativeStdinTerminalDetector;
use App\Support\StdinTerminalDetector;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the stateful boundary services shared by all CLI commands.
 *
 * Keeping credential lookup, client creation, and terminal detection behind
 * container bindings lets commands declare their collaborators while tests
 * can replace operating-system and HTTP boundaries without changing commands.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);
        $this->app->singleton(QuickpayClientFactory::class);
        $this->app->singleton(StdinTerminalDetector::class, NativeStdinTerminalDetector::class);
    }
}
