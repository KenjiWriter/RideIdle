<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\LocaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function (): void {
    Route::get('/meta/locales', [LocaleController::class, 'index']);
});
