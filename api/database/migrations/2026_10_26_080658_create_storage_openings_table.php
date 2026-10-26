<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The storage units a driver has opened, for the audit of the draw and for the counter of the guarantee: what was in each, and how it was drawn.
        Schema::create('storage_openings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('unit', 32);
            // The place of the opening among those of this driver and this unit, from 0: the draw is made from it.
            $table->unsignedInteger('opening_index');
            $table->string('outcome', 16);
            $table->string('vehicle_slug', 64);
            $table->unsignedTinyInteger('age_years');
            $table->unsignedInteger('mileage_km');
            $table->unsignedInteger('condition_ppm');
            $table->unsignedBigInteger('value_cents');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('car_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'unit', 'opening_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_openings');
    }
};
