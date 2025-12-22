<?php

namespace App\Providers;

use App\Services\CacheService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->singleton(CacheService::class, function ($app) {
            return new CacheService();
        });
    }

    public function boot(): void
    {

    }
}
