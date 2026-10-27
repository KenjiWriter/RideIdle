<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A crate that costs nothing (the first one of the tutorial) leaves no posting in the ledger to make a repeated request harmless, so it keeps the
        // request that opened it. The others are made harmless by the posting and leave this empty.
        Schema::table('crate_openings', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('price_cents');
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('crate_openings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'request_id']);
            $table->dropColumn('request_id');
        });
    }
};
