<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The terms and the privacy policy change, so the moment a driver accepted them is not enough: the version of the texts
 * they accepted is saved with it (the day the texts were last changed, see config ride.legal.version). Accounts that
 * accepted before the texts had a version have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('terms_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('terms_version');
        });
    }
};
