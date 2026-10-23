<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_garages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('region', 64);                       // the city it stands in
            $table->unsignedTinyInteger('capacity')->default(3); // how many cars it holds

            $table->timestamps();

            $table->unique(['user_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_garages');
    }
};
