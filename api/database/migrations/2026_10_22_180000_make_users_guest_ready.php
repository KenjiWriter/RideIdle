<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user is the account of one driver. A guest has no e-mail and no password:
 * the account is created in seconds and secured later (claimed) by adding an
 * e-mail, or recovered on another device with a recovery code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            // Lower-cased driver name, unique on every database (PostgreSQL is case sensitive).
            $table->string('name_key', 16)->nullable()->unique();

            // Set when the guest secures the account with an e-mail.
            $table->timestamp('claimed_at')->nullable();

            // Used to expire guest accounts that nobody returns to.
            $table->timestamp('last_seen_at')->nullable()->index();

            // HMAC of the recovery code, never the code itself.
            $table->string('recovery_code_hash', 64)->nullable()->unique();
            $table->timestamp('recovery_code_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['name_key']);
            $table->dropUnique(['recovery_code_hash']);
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['name_key', 'claimed_at', 'last_seen_at', 'recovery_code_hash', 'recovery_code_set_at']);
        });
    }
};
