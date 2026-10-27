<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The upgrade (+N) belongs to the part, like its rarity: it stays with it in the inventory and on any car.
        Schema::table('parts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('upgrade_level')->default(0)->after('rarity');
        });

        // A job of upgrading is paid when it is queued: it keeps the price, so that cancelling a job that has not started gives it back, and the
        // level it takes the part to, which is the one the part has when the job is done.
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_cents')->default(0)->after('kind');
            $table->unsignedTinyInteger('upgrade_to')->nullable()->after('cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->dropColumn(['cost_cents', 'upgrade_to']);
        });

        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('upgrade_level');
        });
    }
};
