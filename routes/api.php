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
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

Route::get('/health', [\App\Http\Controllers\Api\HealthController::class, 'check'])->name('health.check');

// Stripe webhook (no auth; verified via Stripe-Signature)
Route::post('/billing/webhook', [\App\Http\Controllers\Api\StripeWebhookController::class, 'handle'])->name('billing.webhook');

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
        Route::post('/analyze', [CitationController::class, 'analyze'])->middleware('credit.deduct')->name('citations.analyze');
        Route::get('/status/{taskId}', [CitationController::class, 'status'])->name('citations.status');
        Route::get('/results/{taskId}', [CitationController::class, 'results'])->name('citations.results');
        Route::post('/retry/{taskId}', [CitationController::class, 'retry'])->name('citations.retry');
    });

    Route::prefix('keyword-research')->middleware('throttle:10,1')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'create'])->middleware('credit.deduct')->name('keyword-research.create');
        Route::get('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'index'])->name('keyword-research.index');
        Route::get('/{id}/status', [\App\Http\Controllers\Api\KeywordResearchController::class, 'status'])->name('keyword-research.status');
        Route::get('/{id}/results', [\App\Http\Controllers\Api\KeywordResearchController::class, 'results'])->name('keyword-research.results');
    });

    Route::prefix('keyword-planner')->middleware('throttle:20,1')->group(function () {

        Route::get('/ideas', [KeywordPlannerController::class, 'getKeywordIdeas'])
            ->middleware('credit.deduct')
            ->name('keyword-planner.ideas');

        Route::post('/informational-ideas', [KeywordPlannerController::class, 'getInformationalKeywordIdeas'])
            ->middleware('credit.deduct')
            ->name('keyword-planner.informational-ideas');

        Route::post('/for-site', [KeywordPlannerController::class, 'getKeywordsForSite'])
            ->middleware('credit.deduct')
            ->name('keyword-planner.for-site');

        Route::post('/combined-with-clusters', [KeywordPlannerController::class, 'getCombinedKeywordsWithClusters'])
            ->middleware('credit.deduct')
            ->name('keyword-planner.combined-clusters');
    });

    Route::prefix('seo')->middleware('throttle:60,1')->group(function () {

        Route::post('/keywords/search-volume', [DataForSEOController::class, 'searchVolume'])
            ->name('seo.search-volume');

        Route::post('/keywords/for-site', [DataForSEOController::class, 'keywordsForSite'])
            ->name('seo.keywords.for-site');

        Route::prefix('backlinks')->group(function () {
            Route::post('/submit', [BacklinksController::class, 'submit'])
                ->middleware('credit.deduct')
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
            ->middleware('credit.deduct')
            ->name('faq.generate');
        Route::post('/task', [\App\Http\Controllers\Api\FaqController::class, 'createTask'])
            ->middleware('credit.deduct')
            ->name('faq.task.create');
        Route::get('/task/{taskId}', [\App\Http\Controllers\Api\FaqController::class, 'getTaskStatus'])
            ->name('faq.task.status');
    });

    Route::prefix('page-analysis')->middleware('throttle:30,1')->group(function () {
        Route::post('/meta-optimize', [\App\Http\Controllers\Api\PageAnalysis\MetaOptimizerController::class, 'optimize'])
            ->middleware('credit.deduct')
            ->name('page-analysis.meta-optimize');
        Route::get('/meta-optimize/history', [\App\Http\Controllers\Api\PageAnalysis\MetaOptimizerController::class, 'history'])
            ->name('page-analysis.meta-optimize.history');

        Route::post('/semantic-score', [\App\Http\Controllers\Api\PageAnalysis\SemanticScoreController::class, 'evaluate'])
            ->middleware('credit.deduct')
            ->name('page-analysis.semantic-score');
        Route::get('/semantic-score/history', [\App\Http\Controllers\Api\PageAnalysis\SemanticScoreController::class, 'history'])
            ->name('page-analysis.semantic-score.history');

        Route::post('/content-outline', [\App\Http\Controllers\Api\PageAnalysis\ContentOutlineController::class, 'generate'])
            ->middleware('credit.deduct')
            ->name('page-analysis.content-outline');
        Route::get('/content-outline/history', [\App\Http\Controllers\Api\PageAnalysis\ContentOutlineController::class, 'history'])
            ->name('page-analysis.content-outline.history');
    });

    // Billing (credits, checkout, features)
    Route::prefix('billing')->group(function () {
        Route::get('/balance', [\App\Http\Controllers\Api\BillingController::class, 'balance'])->name('billing.balance');
        Route::get('/transactions', [\App\Http\Controllers\Api\BillingController::class, 'transactions'])->name('billing.transactions');
        Route::get('/features', [\App\Http\Controllers\Api\BillingController::class, 'features'])->name('billing.features');
        Route::get('/purchase-rules', [\App\Http\Controllers\Api\BillingController::class, 'purchaseRules'])->name('billing.purchase-rules');
        Route::post('/checkout', [\App\Http\Controllers\Api\BillingController::class, 'createCheckout'])->name('billing.checkout');
        Route::post('/confirm-session', [\App\Http\Controllers\Api\BillingController::class, 'confirmSession'])->name('billing.confirm-session');
    });
});
