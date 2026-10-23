<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ways a driver can sign in to an account: an e-mail, Google, Facebook or Apple. Securing a guest account
 * means linking its first identity. The same identity can belong to one account only, and an account has at
 * most one identity of each provider, so there is never a question which account a sign-in opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // email, google, facebook or apple.
            $table->string('provider', 16);

            // What the provider calls this person for good: the lower-cased e-mail, or the stable user id.
            $table->string('subject', 191);

            // The e-mail as the provider reported it, for showing which identity it is. Never used to find an account.
            $table->string('email', 191)->nullable();

            $table->timestamps();

            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_identities');
    }
};
