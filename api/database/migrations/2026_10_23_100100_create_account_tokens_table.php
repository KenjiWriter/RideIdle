<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time secrets of the account flows, stored only as a hash: the link of an e-mail that secures a guest account
 * (claim) or signs a driver in (login), the state of a sign-in with a provider (oauth), and the code with which the
 * web app trades a finished sign-in for a device token (exchange). Each is short-lived and can be used once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 16);

            // HMAC of the secret, never the secret itself.
            $table->char('token_hash', 64)->unique();

            // The account the token is for: the guest that secures itself, or the account that signs in.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // oauth: which provider and whether the driver links an account or signs in.
            $table->string('provider', 16)->nullable();
            $table->string('intent', 16)->nullable();

            // claim and login: the e-mail the link was sent to.
            $table->string('email', 191)->nullable();

            // When the driver accepted the terms and confirmed their age, to be saved when the account is secured.
            $table->timestamp('consent_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['purpose', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tokens');
    }
};
