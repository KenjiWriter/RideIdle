<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Makes somebody an admin of the panel. The password is made here and shown once; the authenticator app is set up at the first sign-in. */
final class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {email : The address the admin signs in with}
        {--name= : The name shown in the panel (default: the part of the address before the @)}
        {--role=editor : editor (looks and prepares) or publisher (also makes a pack live)}';

    protected $description = 'Make somebody an admin of the content panel, with a password that is shown once';

    public function handle(AdminAudit $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("{$email} is not an e-mail address.");

            return self::FAILURE;
        }

        if (! in_array($role, Admin::ROLES, true)) {
            $this->error('The role is '.implode(' or ', Admin::ROLES).'.');

            return self::FAILURE;
        }

        if (Admin::query()->where('email', $email)->exists()) {
            $this->error("{$email} is an admin already. admin:update changes one.");

            return self::FAILURE;
        }

        $password = Str::password(20, symbols: false);

        Admin::query()->create([
            'name' => (string) ($this->option('name') ?: Str::before($email, '@')),
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ]);
        $audit->record('admin_created', subject: "admin:{$email}", context: ['role' => $role, 'by' => 'cli']);

        $this->info("{$email} is a {$role} now. The password, which is not kept anywhere, is:");
        $this->line($password);
        $this->line('The authenticator app is set up at the first sign-in.');

        return self::SUCCESS;
    }
}
