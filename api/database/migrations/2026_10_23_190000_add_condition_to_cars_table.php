<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            // The average condition of the car in ppm (1 000 000 is perfect). The cars that were there before, and the wrecks of the start, are perfect.
            $table->unsignedInteger('condition_ppm')->default(1_000_000);
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            $table->dropColumn('condition_ppm');
        });
    }
};
