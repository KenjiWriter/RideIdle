<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job of the workshop: putting a part on a car or taking it off. The times are fixed when the job is queued
 * (one mechanic does one job after the other) and the job is carried out lazily, when the driver comes back, by
 * the order of those times, like the settlement of a run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 8);                      // mount or unmount
            $table->string('status', 8)->default('queued'); // queued, working (the car stands in the workshop), done

            $table->bigInteger('start_ms');
            $table->bigInteger('finish_ms');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('part_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_jobs');
    }
};
