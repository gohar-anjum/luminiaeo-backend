<?php

namespace App\Providers;

use App\Models\CitationTask;
use App\Models\FaqTask;
use App\Models\KeywordResearchJob;
use App\Observers\CitationTaskObserver;
use App\Observers\FaqTaskObserver;
use App\Observers\KeywordResearchJobObserver;
use App\Services\CacheService;
use App\Services\PageAnalysis\AnalysisClient;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CacheService::class, function ($app) {
            return new CacheService;
        });

        $this->app->singleton(AnalysisClient::class, function ($app) {
            return AnalysisClient::fromConfig();
        });
    }

    public function boot(): void
    {
        Event::listen(Registered::class, SendEmailVerificationNotification::class);
        CitationTask::observe(CitationTaskObserver::class);
        FaqTask::observe(FaqTaskObserver::class);
        KeywordResearchJob::observe(KeywordResearchJobObserver::class);
    }
}
