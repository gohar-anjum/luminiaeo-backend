<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CitationController;
use App\Http\Controllers\Api\DataForSEO\BacklinksController;
use App\Http\Controllers\Api\DataForSEO\DataForSEOController;
use App\Http\Controllers\Api\KeywordPlannerController;
use App\Http\Controllers\Api\LocationCodeController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    $service = new \App\Services\ApiResponseModifier();
    return $service->setMessage('Login Required')->setResponseCode(401)->response();
})->name('login');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::get('/health', [\App\Http\Controllers\Api\HealthController::class, 'check'])->name('health.check');

// Location codes API (public - no auth required for reading)
Route::prefix('location-codes')->group(function () {
    Route::get('/', [LocationCodeController::class, 'index'])->name('location-codes.index');
    Route::get('/countries', [LocationCodeController::class, 'countries'])->name('location-codes.countries');
    Route::get('/{locationCode}', [LocationCodeController::class, 'show'])->name('location-codes.show');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::put('/user/profile', [UserController::class, 'updateProfile'])->name('user.profile.update');

    Route::prefix('citations')->middleware('throttle:20,1')->group(function () {
        Route::post('/analyze', [CitationController::class, 'analyze'])->name('citations.analyze');
        Route::get('/status/{taskId}', [CitationController::class, 'status'])->name('citations.status');
        Route::get('/results/{taskId}', [CitationController::class, 'results'])->name('citations.results');
        Route::post('/retry/{taskId}', [CitationController::class, 'retry'])->name('citations.retry');
    });

    Route::prefix('keyword-research')->middleware('throttle:10,1')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'create'])->name('keyword-research.create');
        Route::get('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'index'])->name('keyword-research.index');
        Route::get('/{id}/status', [\App\Http\Controllers\Api\KeywordResearchController::class, 'status'])->name('keyword-research.status');
        Route::get('/{id}/results', [\App\Http\Controllers\Api\KeywordResearchController::class, 'results'])->name('keyword-research.results');
    });

    Route::prefix('keyword-planner')->middleware('throttle:20,1')->group(function () {

        Route::get('/ideas', [KeywordPlannerController::class, 'getKeywordIdeas'])
            ->name('keyword-planner.ideas');

        Route::post('/for-site', [KeywordPlannerController::class, 'getKeywordsForSite'])
            ->name('keyword-planner.for-site');

        Route::post('/combined-with-clusters', [KeywordPlannerController::class, 'getCombinedKeywordsWithClusters'])
            ->name('keyword-planner.combined-clusters');
    });

    Route::prefix('seo')->middleware('throttle:60,1')->group(function () {

        Route::post('/keywords/search-volume', [DataForSEOController::class, 'searchVolume'])
            ->name('seo.search-volume');

        Route::post('/keywords/for-site', [DataForSEOController::class, 'keywordsForSite'])
            ->name('seo.keywords.for-site');

        Route::prefix('backlinks')->group(function () {
            Route::post('/submit', [BacklinksController::class, 'submit'])
                ->name('seo.backlinks.submit');
            Route::post('/results', [BacklinksController::class, 'results'])
                ->name('seo.backlinks.results');
            Route::post('/status', [BacklinksController::class, 'status'])
                ->name('seo.backlinks.status');
            Route::post('/harmful', [BacklinksController::class, 'harmful'])
                ->name('seo.backlinks.harmful');
        });
    });

    Route::prefix('serp')->middleware('throttle:60,1')->group(function () {
        Route::post('/keywords', [\App\Http\Controllers\Api\Serp\SerpController::class, 'keywordData'])
            ->name('serp.keywords');
        Route::post('/results', [\App\Http\Controllers\Api\Serp\SerpController::class, 'serpResults'])
            ->name('serp.results');
    });

    Route::prefix('faq')->middleware('throttle:30,1')->group(function () {
        Route::post('/generate', [\App\Http\Controllers\Api\FaqController::class, 'generate'])
            ->name('faq.generate');
        Route::post('/task', [\App\Http\Controllers\Api\FaqController::class, 'createTask'])
            ->name('faq.task.create');
        Route::get('/task/{taskId}', [\App\Http\Controllers\Api\FaqController::class, 'getTaskStatus'])
            ->name('faq.task.status');
    });
});
