<?php

namespace App\Providers;

use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Interfaces\KeywordRepositoryInterface;
use App\Repositories\DataForSEO\BacklinksRepository;
use App\Repositories\KeywordRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeywordRepositoryInterface::class, KeywordRepository::class);
        $this->app->bind(BacklinksRepositoryInterface::class, BacklinksRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
