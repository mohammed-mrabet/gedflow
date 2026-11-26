<?php

use App\Http\Controllers\Api\DocumentApiController;
use App\Http\Controllers\Api\TaskApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

// API REST v1 — authentification par jeton personnel (Laravel Sanctum) avec capacités :
// documents:read, documents:write, tasks:decide. Documentation : /api/docs
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', fn (Request $request) => [
        'id' => $request->user()->id,
        'name' => $request->user()->name,
        'role' => $request->user()->role->value,
        'department' => $request->user()->department?->name,
        'abilities' => $request->user()->currentAccessToken() instanceof PersonalAccessToken
            ? $request->user()->currentAccessToken()->abilities : ['*'],
    ]);

    Route::middleware('abilities:documents:read')->group(function () {
        Route::get('/documents', [DocumentApiController::class, 'index']);
        Route::get('/documents/{document}', [DocumentApiController::class, 'show']);
        Route::get('/documents/{document}/versions/{version}/file', [DocumentApiController::class, 'download']);
    });

    Route::middleware('abilities:documents:write')->group(function () {
        Route::post('/documents', [DocumentApiController::class, 'store']);
        Route::post('/documents/{document}/versions', [DocumentApiController::class, 'storeVersion']);
        Route::post('/documents/{document}/submit', [DocumentApiController::class, 'submit']);
    });

    Route::middleware('abilities:tasks:decide')->group(function () {
        Route::get('/tasks', [TaskApiController::class, 'index']);
        Route::post('/tasks/{task}/decision', [TaskApiController::class, 'decide']);
    });
});
