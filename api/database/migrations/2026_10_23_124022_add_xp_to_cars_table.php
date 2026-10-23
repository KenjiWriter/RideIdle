<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The experience of a car: the laps it has driven since it was got, which is what its level rises on. A car that exists
 * already is given the experience at which its level begins, 100 * level * (level - 1) with the 200 a level of the
 * rules: the laps it drove before this are not counted, because nothing recorded them for the car.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            $table->unsignedBigInteger('xp')->default(0)->after('level');
        });

        DB::table('cars')->update(['xp' => DB::raw('100 * level * (level - 1)')]);
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            $table->dropColumn('xp');
        });
    }
};
