<?php

use App\Http\Controllers\AccountDisclosureController;
use App\Http\Controllers\AdminController;
use App\Http\Middleware\RequireAdministrator;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/account/disclosure', [AccountDisclosureController::class, 'show']);
    Route::post('/account/disclosure', [AccountDisclosureController::class, 'accept']);
    Route::delete('/account/disclosure', [AccountDisclosureController::class, 'decline']);
    Route::middleware([RequireAdministrator::class, 'throttle:120,1'])->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/collections', [AdminController::class, 'collections']);
        Route::get('/collections/{collection}', [AdminController::class, 'collection']);
        Route::patch('/collections/{collection}', [AdminController::class, 'updateCollection']);
        Route::delete('/collections/{collection}/sharing', [AdminController::class, 'disableSharing']);
    });
});
