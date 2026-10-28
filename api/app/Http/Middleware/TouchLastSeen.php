<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers when a driver was last active, so abandoned guest accounts can expire.
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

            // A plain update keeps updated_at untouched and skips model events. The visit before is kept: the tutorial reads the length of a break from it.
            User::query()->whereKey($user->getKey())->update(['previous_seen_at' => $user->last_seen_at, 'last_seen_at' => $now]);
            $user->setAttribute('previous_seen_at', $user->last_seen_at);
            $user->setAttribute('last_seen_at', $now);
        }

        return $next($request);
    }
}
