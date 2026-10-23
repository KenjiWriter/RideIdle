<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A run drives a car the driver owns. The runs that exist were started with the slug of a car only, so each of
 * them gets a car row of its own, at the level a new car has.
 */
return new class extends Migration
{
    /** The level of a new car in the rules of the time (content/rules/starter.json, car.start_level). */
    private const START_LEVEL = 3;

    public function up(): void
    {
        Schema::table('race_runs', function (Blueprint $table): void {
            $table->foreignId('car_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        DB::table('race_runs')->whereNull('car_id')->orderBy('id')->chunkById(100, function ($runs): void {
            foreach ($runs as $run) {
                $carId = DB::table('cars')->insertGetId([
                    'user_id' => $run->user_id,
                    'vehicle_slug' => $run->car_slug,
                    'level' => self::START_LEVEL,
                    'created_at' => $run->created_at,
                    'updated_at' => $run->created_at,
                ]);

                DB::table('race_runs')->where('id', $run->id)->update(['car_id' => $carId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('race_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('car_id');
        });
    }
};
