<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How worn a part is, in billionths of its life (a billion is worn out): the wear of a lap is an integer, so that the sum of the laps is exact.
        Schema::table('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('wear_ppb')->default(0)->after('upgrade_level');
        });

        // The odometer of the car in metres, and where it was serviced last: the service falls due an interval after that.
        Schema::table('cars', function (Blueprint $table): void {
            $table->unsignedBigInteger('mileage_m')->default(0)->after('condition_ppm');
            $table->unsignedBigInteger('serviced_at_m')->default(0)->after('mileage_m');
        });

        // The lap after which the frozen configuration of the run has to be made again, because a part has worn to its next stage or the service moved on.
        Schema::table('race_runs', function (Blueprint $table): void {
            $table->bigInteger('recompute_after_laps')->nullable()->after('break_after_laps');
        });

        // A service is a job on the car and no part: the job has none.
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('part_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('race_runs', function (Blueprint $table): void {
            $table->dropColumn('recompute_after_laps');
        });

        Schema::table('cars', function (Blueprint $table): void {
            $table->dropColumn(['mileage_m', 'serviced_at_m']);
        });

        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('wear_ppb');
        });
    }
};
