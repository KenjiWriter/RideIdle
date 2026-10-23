<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            // The workshop is a city's: each has its own mechanic and its own queue. Jobs from before the cities were all in the first one.
            $table->string('city', 64)->default('zlomowo');
            $table->index(['user_id', 'city', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'city', 'status']);
            $table->dropColumn('city');
        });
    }
};
