<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountDataController;
use App\Http\Controllers\Api\V1\AccountEmailController;
use App\Http\Controllers\Api\V1\AccountRecoveryController;
use App\Http\Controllers\Api\V1\AuthProviderController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\GarageController;
use App\Http\Controllers\Api\V1\LegalController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\MarketController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\PremiumController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\StarterCarController;
use App\Http\Controllers\Api\V1\TutorialController;
use App\Http\Controllers\Api\V1\WorldController;
use App\Http\Middleware\TouchLastSeen;
use App\Models\AccountIdentity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/meta/locales', [LocaleController::class, 'index']);
    Route::get('/meta/legal', [LegalController::class, 'show']);

    // The sign-ins with another service that are on, and the way in and out of each of them. The providers are
    // Google, Facebook and Apple; the callback is where the provider sends the person back (Apple with a form post).
    Route::get('/auth/providers', [AuthProviderController::class, 'index']);
    Route::post('/auth/complete', [SocialAuthController::class, 'complete'])->middleware('throttle:account-confirm');
    Route::post('/auth/{provider}/start', [SocialAuthController::class, 'startLogin'])->whereIn('provider', AccountIdentity::SOCIAL)->middleware('throttle:account-oauth-start');
    Route::match(['get', 'post'], '/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->whereIn('provider', AccountIdentity::SOCIAL);

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
        // A copy of the data of the account, and, in the same place, having the account deleted.
        Route::get('/account/export', [AccountDataController::class, 'export'])->middleware('throttle:account-data');
        Route::delete('/account', [AccountDataController::class, 'destroy'])->middleware('throttle:account-erase');
        Route::post('/account/recovery-code', [AccountRecoveryController::class, 'issue'])->middleware('throttle:recovery-issue');
        Route::post('/account/email', [AccountEmailController::class, 'store'])->middleware('throttle:account-email');
        Route::post('/account/identities/{provider}/start', [SocialAuthController::class, 'startLink'])->whereIn('provider', AccountIdentity::SOCIAL)->middleware('throttle:account-oauth-start');

        Route::get('/starter-cars', StarterCarController::class);

        Route::get('/cars', [FleetController::class, 'index']);
        Route::post('/cars', [FleetController::class, 'buyCar']);
        Route::post('/cars/{car}/select', [FleetController::class, 'select'])->whereNumber('car');
        Route::post('/cars/{car}/sell', [FleetController::class, 'sell'])->whereNumber('car');
        Route::post('/garages', [FleetController::class, 'buyGarage']);

        Route::get('/market', [MarketController::class, 'show']);
        Route::post('/market/exchange/{key}/inspect', [MarketController::class, 'inspect'])->where('key', '[a-z0-9.-]+');
        Route::post('/market/exchange/{key}/buy', [MarketController::class, 'buyListing'])->where('key', '[a-z0-9.-]+');
        Route::post('/market/dealer', [MarketController::class, 'buyNew']);
        Route::post('/market/storage/{unit}/open', [MarketController::class, 'openStorage'])->where('unit', '[a-z0-9-]+');

        Route::get('/map', [WorldController::class, 'show']);
        Route::post('/travel', [WorldController::class, 'travel']);

        Route::get('/garage', [GarageController::class, 'show']);
        Route::post('/garage/jobs', [GarageController::class, 'queueJob']);
        Route::delete('/garage/jobs/{job}', [GarageController::class, 'cancelJob'])->whereNumber('job');
        Route::post('/garage/equipment', [GarageController::class, 'buyEquipment']);

        Route::get('/tutorial', [TutorialController::class, 'show']);
        Route::post('/tutorial/reply', [TutorialController::class, 'reply']);
        Route::post('/tutorial/decline', [TutorialController::class, 'decline']);
        Route::post('/tutorial/skip', [TutorialController::class, 'skip']);

        Route::get('/premium', [PremiumController::class, 'show']);

        Route::get('/shop', [ShopController::class, 'index']);
        Route::post('/shop/purchases', [ShopController::class, 'purchase']);
        Route::post('/shop/crates/{kind}/open', [ShopController::class, 'openCrate'])->where('kind', '[a-z0-9-]+');

        Route::post('/runs', [RunController::class, 'store']);
        Route::get('/runs/current', [RunController::class, 'current']);
        Route::get('/runs/{run}', [RunController::class, 'show'])->whereNumber('run');
        Route::patch('/runs/{run}', [RunController::class, 'update'])->whereNumber('run');
        Route::post('/runs/{run}/start', [RunController::class, 'start'])->whereNumber('run');
        Route::post('/runs/{run}/refuel', [RunController::class, 'refuel'])->whereNumber('run');
        Route::post('/runs/{run}/repair', [RunController::class, 'repair'])->whereNumber('run');
        Route::post('/runs/{run}/tow', [RunController::class, 'tow'])->whereNumber('run');
    });
});
