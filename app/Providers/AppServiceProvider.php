<?php

namespace App\Providers;

use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);
        $this->app->singleton(QuickpayClientFactory::class);
    }
}
