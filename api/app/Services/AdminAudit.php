<?php

declare(strict_types=1);

namespace App\Services;

use App\Games\CurrentGame;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * The trail of the admin panel: what an admin did, in a table that is only added to. The admin is the one who is signed in unless it is given (a sign-in that
 * has just happened, an attempt that failed), and the address is the one of the request, if there is one. The game is the one that was put in force (the one the admin
 * works in in the panel, or the one of a command that was given `--game`); what was done before one was, or outside any game, has none.
 */
final class AdminAudit
{
    /**
     * @param  array<string, mixed>  $context  more about it, written as JSON
     * @param  string|null  $email  the address somebody tried to sign in with, when there is no admin to name
     */
    public function record(string $action, ?Admin $admin = null, ?string $subject = null, array $context = [], ?string $email = null): AdminAuditLog
    {
        $admin ??= Auth::guard('admin')->user();

        return AdminAuditLog::query()->create([
            'admin_id' => $admin?->id,
            'game_id' => app(CurrentGame::class)->game()?->id,
            'email' => $admin?->email ?? $email,
            'action' => $action,
            'subject' => $subject,
            'context' => $context === [] ? null : $context,
            'ip' => request()->ip(),
        ]);
    }
}
