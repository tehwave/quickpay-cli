<?php

namespace App\Providers;

use App\Console\Output\QuickpayDescriber;
use App\Console\Terminal\NativeStdinTerminalDetector;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKeyResolver;
use App\Credentials\CredentialFile;
use App\Credentials\EnvironmentVariables;
use App\Credentials\HomeDirectory;
use App\Quickpay\AuthenticatedQuickpayFactory;
use Illuminate\Support\ServiceProvider;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;

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
        $this->app->singleton(EnvironmentVariables::class);
        $this->app->singleton(HomeDirectory::class);
        $this->app->singleton(
            CredentialFile::class,
            fn ($app): CredentialFile => CredentialFile::inHome($app->make(HomeDirectory::class)),
        );
        $this->app->singleton(ApiKeyResolver::class);
        $this->app->singleton(AuthenticatedQuickpayFactory::class);
        $this->app->singleton(StdinTerminalDetector::class, NativeStdinTerminalDetector::class);
    }

    public function boot(): void
    {
        $this->app->singleton(DescriberContract::class, QuickpayDescriber::class);
    }
}
