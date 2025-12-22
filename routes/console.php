<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('keyword-cache:refresh --expired --cleanup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('keyword-cache:refresh --clusters --expiring')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::job(new \App\Jobs\SyncKeywordCacheJob(
    keywords: null,
    languageCode: 'en',
    locationCode: 2840,
    source: 'serp_api',
    refreshExpired: false,
    refreshClusters: false
))->everySixHours()->withoutOverlapping();
