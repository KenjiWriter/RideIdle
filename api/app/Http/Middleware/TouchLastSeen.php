<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserVisit;
use App\Services\AwaySummary;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers when a driver was last active, so abandoned guest accounts can expire, and writes down every visit (the first request after a break of ten minutes).
 * The write happens at most once every ten minutes per account.
 */
final class TouchLastSeen
{
    private const INTERVAL_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(self::INTERVAL_MINUTES)))) {
            $now = now();

            // A break that is long enough is noted for the summary of what happened in it (`AwaySummary`).
            $away = app(AwaySummary::class)->note($user, $user->last_seen_at, $now);

            // A plain update keeps updated_at untouched and skips model events. The visit before is kept: the tutorial reads the length of a break from it.
            User::query()->whereKey($user->getKey())->update(['previous_seen_at' => $user->last_seen_at, 'last_seen_at' => $now, ...$away]);
            $user->setAttribute('previous_seen_at', $user->last_seen_at);
            $user->setAttribute('last_seen_at', $now);

            foreach ($away as $column => $moment) {
                $user->setAttribute($column, $moment);
            }

            // The visit is written down for the numbers of the alpha (`Metrics`): when the drivers come back, and for how long.
            UserVisit::query()->create(['user_id' => $user->getKey(), 'at' => $now, 'gap_minutes' => $user->previous_seen_at === null ? null : (int) $user->previous_seen_at->diffInMinutes($now, true)]);
        }

        return $next($request);
    }
}
