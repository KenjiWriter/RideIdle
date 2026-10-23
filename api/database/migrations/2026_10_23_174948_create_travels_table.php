<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_run_id')->constrained('race_runs')->cascadeOnDelete();

            $table->string('from_region', 64);
            $table->string('to_region', 64);
            $table->unsignedInteger('distance_km');
            $table->unsignedInteger('fuel_ml');         // taken from the tank when the car leaves

            $table->bigInteger('start_ms');
            $table->bigInteger('arrive_ms');
            $table->string('status', 12)->default('travelling'); // travelling, arrived

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travels');
    }
};
