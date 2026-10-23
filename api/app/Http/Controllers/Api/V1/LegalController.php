<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class LegalController extends Controller
{
    /**
     * What the terms and the privacy policy of the web app need from the server: the minimum age a driver confirms,
     * the version of the texts (the day they were last changed), how many days a guest account is kept without
     * activity (the privacy policy must say how long data is kept, so it cannot drift from the setting), and who runs
     * the game. A detail of the operator that was not set is null, and the page says it is missing instead of leaving
     * a gap.
     */
    public function show(): JsonResponse
    {
        $legal = (array) config('ride.legal');
        $operator = (array) ($legal['operator'] ?? []);

        return response()->json(['data' => [
            'min_age' => (int) $legal['min_age'],
            'version' => (string) $legal['version'],
            'guest_expiry_days' => (int) config('ride.guest_expiry_days'),
            'operator' => [
                'name' => filled($operator['name'] ?? null) ? (string) $operator['name'] : null,
                'address' => filled($operator['address'] ?? null) ? (string) $operator['address'] : null,
                'email' => filled($operator['email'] ?? null) ? (string) $operator['email'] : null,
            ],
        ]]);
    }
}
