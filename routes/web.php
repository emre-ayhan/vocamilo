<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/dashboard' : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1');
    Route::get('/auth/google/link', [GoogleAuthController::class, 'linkForm']);
    Route::post('/auth/google/link', [GoogleAuthController::class, 'link'])->middleware('throttle:5,1');
    Route::post('/auth/google/link/cancel', [GoogleAuthController::class, 'cancel']);
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'register']);
    Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [VocabularyController::class, 'dashboard']);
    Route::get('/words', [VocabularyController::class, 'index']);
    Route::post('/words', [VocabularyController::class, 'store']);
    Route::put('/words/{word}', [VocabularyController::class, 'update']);
    Route::delete('/words/{word}', [VocabularyController::class, 'destroy']);
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/imports/preview', [ImportController::class, 'preview'])->middleware('throttle:10,1');
    Route::post('/imports/store', [ImportController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::post('/collections', [CollectionController::class, 'store']);
    Route::get('/collections/{collection}', [CollectionController::class, 'show']);
    Route::put('/collections/{collection}', [CollectionController::class, 'update']);
    Route::delete('/collections/{collection}', [CollectionController::class, 'destroy']);
    Route::post('/collections/{collection}/words', [CollectionController::class, 'addWords']);
    Route::delete('/collections/{collection}/words/{word}', [CollectionController::class, 'removeWord']);
    Route::post('/collections/{collection}/sharing', [CollectionController::class, 'share']);
    Route::delete('/collections/{collection}/sharing', [CollectionController::class, 'unshare']);
    Route::get('/collections/{collection}/study', [CollectionController::class, 'study']);
});
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/s/{uuid}', [CollectionController::class, 'publicShow']);
    Route::get('/s/{uuid}/study', [CollectionController::class, 'publicStudy']);
});
