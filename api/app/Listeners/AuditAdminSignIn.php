<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Admin;
use App\Services\AdminAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Writes the sign-ins and sign-outs of the admin panel to the trail, and the attempts that failed, with the address that was tried. The events carry the name of
 * the guard, so what the players do with their own guard is not in it.
 */
final class AuditAdminSignIn
{
    public const GUARD = 'admin';

    public function __construct(private readonly AdminAudit $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->signedIn(...));
        $events->listen(Failed::class, $this->failed(...));
        $events->listen(Logout::class, $this->signedOut(...));
    }

    private function signedIn(Login $event): void
    {
        if ($event->guard !== self::GUARD || ! $event->user instanceof Admin) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('login', $event->user);
    }

    private function failed(Failed $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $email = $event->credentials['email'] ?? null;

        $this->audit->record('login_failed', $event->user instanceof Admin ? $event->user : null, email: is_string($email) ? $email : null);
    }

    private function signedOut(Logout $event): void
    {
        if ($event->guard === self::GUARD && $event->user instanceof Admin) {
            $this->audit->record('logout', $event->user);
        }
    }
}
