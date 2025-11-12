<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interfaces\Repositories\KeywordRepositoryInterface;
use App\Repositories\KeywordRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeywordRepositoryInterface::class, KeywordRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
