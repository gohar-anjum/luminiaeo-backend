<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CitationController;
use App\Http\Controllers\Api\DataForSEO\BacklinksController;
use App\Http\Controllers\Api\DataForSEO\DataForSEOController;
use App\Http\Controllers\Api\KeywordPlannerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/login', function () {
    $service = new \App\Services\ApiResponseModifier();
    return $service->setMessage('Login Required')->setResponseCode(401)->response();
})->name('login');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::prefix('citations')->middleware('throttle:20,1')->group(function () {
        Route::post('/analyze', [CitationController::class, 'analyze'])->name('citations.analyze');
        Route::get('/status/{taskId}', [CitationController::class, 'status'])->name('citations.status');
        Route::get('/results/{taskId}', [CitationController::class, 'results'])->name('citations.results');
        Route::post('/retry/{taskId}', [CitationController::class, 'retry'])->name('citations.retry');
    });

    // Keyword research routes
    Route::prefix('keyword-research')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'create'])->name('keyword-research.create');
        Route::get('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'index'])->name('keyword-research.index');
        Route::get('/{id}/status', [\App\Http\Controllers\Api\KeywordResearchController::class, 'status'])->name('keyword-research.status');
        Route::get('/{id}/results', [\App\Http\Controllers\Api\KeywordResearchController::class, 'results'])->name('keyword-research.results');
    });

    // Keyword planner routes (legacy)
    Route::get('/keyword-ideas', [KeywordPlannerController::class, 'getKeywordIdeas']);

    // DataForSEO routes with rate limiting
    Route::prefix('seo')->middleware('throttle:60,1')->group(function () {
        // Search volume routes
        Route::post('/keywords/search-volume', [DataForSEOController::class, 'searchVolume'])
            ->name('seo.search-volume');

        // Backlinks routes
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
});
