<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A job of merging is not on a car (the parts are in the inventory), so the car stands for nothing: its car is empty. The second part of the
        // merge is kept on the job, so that both parts are reserved while it waits.
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('car_id')->nullable()->change();
            $table->unsignedBigInteger('merge_part_id')->nullable()->after('part_id');
            $table->index('merge_part_id');
        });

        // Every attempt of merging, for the audit of the draw and for the protection from bad luck, which counts the failures since the last success at
        // the slot and the rarity. The draw is made from the seed when the mechanic is done.
        Schema::create('merge_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            // The job is gone with the parts when the attempt fails, so this is no key, only what to look for in the log.
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('slot', 32);
            $table->unsignedTinyInteger('tier');
            $table->unsignedTinyInteger('to_rarity');
            $table->string('part_slug', 64);
            $table->unsignedBigInteger('part_id')->nullable();
            $table->unsignedInteger('chance_ppm');
            $table->unsignedInteger('roll_ppm');
            $table->boolean('success');
            $table->unsignedBigInteger('scrap_cents')->default(0);
            $table->string('seed', 64);
            $table->bigInteger('finished_ms');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'slot', 'to_rarity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_attempts');

        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->dropIndex(['merge_part_id']);
            $table->dropColumn('merge_part_id');
        });
    }
};
