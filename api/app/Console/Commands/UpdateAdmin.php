<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Changes an admin of the panel: the role, whether they can sign in at all, a new password, or the authenticator app taken off (for the admin who lost the
 * phone, who sets it up again at the next sign-in). It is the way in when the panel itself cannot be used.
 */
final class UpdateAdmin extends Command
{
    protected $signature = 'admin:update
        {email : The address of the admin}
        {--role= : editor or publisher}
        {--activate : Let the admin sign in}
        {--deactivate : Do not let the admin sign in}
        {--reset-password : Make a new password, shown once}
        {--reset-mfa : Take the authenticator app and the recovery codes off}';

    protected $description = 'Change the role of an admin of the content panel, switch them off or on, give them a new password or reset their authenticator app';

    public function handle(AdminAudit $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $admin = Admin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->error("There is no admin {$email}.");

            return self::FAILURE;
        }

        if ($this->option('activate') && $this->option('deactivate')) {
            $this->error('An admin cannot be switched on and off at once.');

            return self::FAILURE;
        }

        $role = $this->option('role');

        if ($role !== null && ! in_array($role, Admin::ROLES, true)) {
            $this->error('The role is '.implode(' or ', Admin::ROLES).'.');

            return self::FAILURE;
        }

        $changes = [];
        $password = null;

        if ($role !== null && $role !== $admin->role) {
            $admin->role = $role;
            $changes[] = "role: {$role}";
        }

        if ($this->option('activate') || $this->option('deactivate')) {
            $admin->is_active = (bool) $this->option('activate');
            $changes[] = $admin->is_active ? 'switched on' : 'switched off';
        }

        if ($this->option('reset-password')) {
            $password = Str::password(20, symbols: false);
            $admin->password = $password;
            $changes[] = 'new password';
        }

        if ($this->option('reset-mfa')) {
            $admin->app_authentication_secret = null;
            $admin->app_authentication_recovery_codes = null;
            $changes[] = 'authenticator app taken off';
        }

        if ($changes === []) {
            $this->line('Nothing to change: give --role, --activate, --deactivate, --reset-password or --reset-mfa.');

            return self::SUCCESS;
        }

        $admin->save();
        $audit->record('admin_updated', subject: "admin:{$email}", context: ['changes' => $changes, 'by' => 'cli']);

        $this->info("{$email}: ".implode(', ', $changes).'.');

        if ($password !== null) {
            $this->line("The new password, which is not kept anywhere, is: {$password}");
        }

        return self::SUCCESS;
    }
}
