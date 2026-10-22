<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountRecoveryController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta/locales', [LocaleController::class, 'index']);

    // A new guest driver. Limited per address to make mass account creation expensive.
    Route::post('/players', [PlayerController::class, 'store'])->middleware('throttle:guest-create');

    // Sign in on a new device with a recovery code.
    Route::post('/account/recover', [AccountRecoveryController::class, 'recover'])->middleware('throttle:recovery-attempts');

    Route::middleware(['auth:sanctum', TouchLastSeen::class])->group(function (): void {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me/locale', [MeController::class, 'updateLocale']);
        Route::post('/account/recovery-code', [AccountRecoveryController::class, 'issue'])->middleware('throttle:recovery-issue');

        Route::post('/runs', [RunController::class, 'store']);
        Route::get('/runs/current', [RunController::class, 'current']);
        Route::get('/runs/{run}', [RunController::class, 'show'])->whereNumber('run');
    });
});
