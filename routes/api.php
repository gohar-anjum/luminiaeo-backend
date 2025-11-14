<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DataForSEO\BacklinksController;
use App\Http\Controllers\Api\DataForSEO\DataForSEOController;
use App\Http\Controllers\Api\KeywordController;
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

    // Keywords routes
    Route::prefix('keywords')->group(function () {
        Route::get('/', [KeywordController::class, 'index'])->name('keywords.index');
        Route::post('/', [KeywordController::class, 'store'])->name('keywords.store');
        Route::put('/{id}', [KeywordController::class, 'update'])->name('keywords.update');
        Route::delete('/{id}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
    });

    // Keyword planner routes
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
