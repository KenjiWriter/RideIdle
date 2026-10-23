<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sign-in with a provider is bound to the browser that started it, so that somebody cannot make a victim finish a
 * flow that the attacker started (login CSRF): the browser keeps a verifier, and the token holds only its hash
 * (`challenge`). The result of the provider waits in a token too: who the provider says the person is (`subject`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_tokens', function (Blueprint $table): void {
            $table->char('challenge', 64)->nullable();
            $table->string('subject', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_tokens', function (Blueprint $table): void {
            $table->dropColumn(['challenge', 'subject']);
        });
    }
};
