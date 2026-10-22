<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\RecoveryCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AccountRecoveryController extends Controller
{
    /** Failed attempts from one address within the window that trigger the lock. */
    private const MAX_FAILURES = 5;

    private const FAILURE_WINDOW_SECONDS = 3600;

    private const LOCK_SECONDS = 86400;

    /**
     * Creates a new recovery code and replaces the previous one. The code is
     * returned once; afterwards only its hash exists.
     */
    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = RecoveryCode::generate();

        $user->forceFill([
            'recovery_code_hash' => RecoveryCode::hash((string) RecoveryCode::normalize($code)),
            'recovery_code_set_at' => now(),
        ])->save();

        return response()->json(['data' => new MeResource($user), 'code' => $code]);
    }

    /**
     * Signs the driver in on a new device with a recovery code. Five failed
     * attempts from one address lock recovery from that address for 24 hours.
     */
    public function recover(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        $ip = (string) $request->ip();
        $lockKey = "recovery-lock:{$ip}";
        $failuresKey = "recovery-failures:{$ip}";

        $lockedUntil = Cache::get($lockKey);

        if ($lockedUntil !== null) {
            return response()
                ->json(['message' => __('account.recovery.locked')], 429)
                ->header('Retry-After', (string) max(1, (int) $lockedUntil - now()->getTimestamp()));
        }

        $normalized = RecoveryCode::normalize($request->string('code')->toString());
        $user = $normalized === null
            ? null
            : User::query()->where('recovery_code_hash', RecoveryCode::hash($normalized))->first();

        if ($user === null) {
            RateLimiter::hit($failuresKey, self::FAILURE_WINDOW_SECONDS);

            if (RateLimiter::attempts($failuresKey) >= self::MAX_FAILURES) {
                Cache::put($lockKey, now()->getTimestamp() + self::LOCK_SECONDS, self::LOCK_SECONDS);
                RateLimiter::clear($failuresKey);
            }

            throw ValidationException::withMessages(['code' => __('account.recovery.invalid')]);
        }

        RateLimiter::clear($failuresKey);
        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'data' => new MeResource($user),
            'token' => $user->createToken('recovered-device')->plainTextToken,
        ]);
    }
}
