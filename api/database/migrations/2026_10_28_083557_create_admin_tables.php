<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin panel (F4, step S2): the people who may edit the content of the game, kept apart from the players, and the trail of what they did. A player can never
 * become an admin by linking an account, and an admin is not a player; they sign in with a password and a code of an authenticator app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 16)->default('editor');         // editor: looks and prepares; publisher: also makes a pack live
            $table->boolean('is_active')->default(true);            // switched off, an admin cannot sign in, and the row stays for the trail
            $table->text('app_authentication_secret')->nullable();  // the secret of the authenticator app, encrypted
            $table->text('app_authentication_recovery_codes')->nullable(); // the recovery codes, encrypted
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Only added to. The admin may be gone later; what they did stays, with the address they were known by.
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('email')->nullable();       // who it was, or who somebody tried to be
            $table->string('action', 48);              // login, login_failed, logout, content_pack_activated ...
            $table->string('subject', 96)->nullable(); // what it was done to: content_pack:2026.10.1
            $table->text('context')->nullable();       // more, as JSON
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('admins');
    }
};
