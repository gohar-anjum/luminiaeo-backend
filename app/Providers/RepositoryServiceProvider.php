<?php

namespace App\Providers;

use App\Interfaces\CitationRepositoryInterface;
use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Interfaces\FaqRepositoryInterface;
use App\Interfaces\KeywordRepositoryInterface;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Repositories\CitationRepository;
use App\Repositories\DataForSEO\BacklinksRepository;
use App\Repositories\FaqRepository;
use App\Repositories\KeywordRepository;
use App\Repositories\KeywordCacheRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeywordRepositoryInterface::class, KeywordRepository::class);
        $this->app->bind(BacklinksRepositoryInterface::class, BacklinksRepository::class);
        $this->app->bind(CitationRepositoryInterface::class, CitationRepository::class);
        $this->app->bind(KeywordCacheRepositoryInterface::class, KeywordCacheRepository::class);
        $this->app->bind(FaqRepositoryInterface::class, FaqRepository::class);
    }

    public function boot(): void
    {

    }
}
