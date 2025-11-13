<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KeywordController;
use App\Http\Controllers\Api\KeywordPlannerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::get('/login',function(){
    $service = new \App\Services\ApiResponseModifier();
    return $service->setMessage('Login Required')->setResponseCode(401)->response();
})->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
Route::prefix('keywords')->group(function () {
    Route::get('/', [KeywordController::class, 'index'])->name('keywords.index');
    Route::post('/', [KeywordController::class, 'store'])->name('keywords.store');
    Route::put('/{id}', [KeywordController::class, 'update'])->name('keywords.update');
    Route::delete('/{id}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
});
Route::get('/keyword-ideas', [KeywordPlannerController::class, 'getKeywordIdeas'])->middleware('auth:sanctum');
Route::post('/keywords/data',[\App\Http\Controllers\Api\DataForSEO\DataForSEOController::class,'searchVolume']);
