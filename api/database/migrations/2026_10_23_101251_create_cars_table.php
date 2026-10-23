<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A car a driver owns: the instance of a vehicle definition of the content pack. Its parts are rows of `parts`
 * that point to it. Until now a run held only the slug of the car it drove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Slug of the definition in the content pack.
            $table->string('vehicle_slug', 64);

            // The level decides the highest tier of a part that can be mounted (rules: car.levels_per_tier).
            $table->unsignedSmallInteger('level');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
