<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountEmailController;
use App\Http\Controllers\Api\V1\AccountRecoveryController;
use App\Http\Controllers\Api\V1\AuthProviderController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\StarterCarController;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta/locales', [LocaleController::class, 'index']);

    // The sign-ins with another service that are on.
    Route::get('/auth/providers', [AuthProviderController::class, 'index']);

    // A new guest driver. Limited per address to make mass account creation expensive.
    Route::post('/players', [PlayerController::class, 'store'])->middleware('throttle:guest-create');

    // Sign in on a new device with a recovery code.
    Route::post('/account/recover', [AccountRecoveryController::class, 'recover'])->middleware('throttle:recovery-attempts');

    // Ask for a link that signs in on a new device. The answer does not tell whether the address has a garage.
    Route::post('/account/login-link', [AccountEmailController::class, 'requestLoginLink'])->middleware('throttle:account-login-link');

    // Use the link of an e-mail: it secures an account or signs a driver in. The secret in it cannot be guessed.
    Route::post('/account/email/confirm', [AccountEmailController::class, 'confirm'])->middleware('throttle:account-confirm');

    Route::middleware(['auth:sanctum', TouchLastSeen::class])->group(function (): void {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me/locale', [MeController::class, 'updateLocale']);
        Route::post('/account/recovery-code', [AccountRecoveryController::class, 'issue'])->middleware('throttle:recovery-issue');
        Route::post('/account/email', [AccountEmailController::class, 'store'])->middleware('throttle:account-email');

        Route::get('/starter-cars', StarterCarController::class);

        Route::post('/runs', [RunController::class, 'store']);
        Route::get('/runs/current', [RunController::class, 'current']);
        Route::get('/runs/{run}', [RunController::class, 'show'])->whereNumber('run');
        Route::patch('/runs/{run}', [RunController::class, 'update'])->whereNumber('run');
        Route::post('/runs/{run}/refuel', [RunController::class, 'refuel'])->whereNumber('run');
        Route::post('/runs/{run}/repair', [RunController::class, 'repair'])->whereNumber('run');
        Route::post('/runs/{run}/tow', [RunController::class, 'tow'])->whereNumber('run');
    });
});
