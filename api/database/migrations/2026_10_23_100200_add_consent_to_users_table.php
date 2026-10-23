<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest account holds no personal data. Securing it asks for an e-mail or a provider, so the driver also accepts the
 * terms and the privacy policy and confirms that they are old enough. When they did it is saved on the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('age_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'age_confirmed_at']);
        });
    }
};
