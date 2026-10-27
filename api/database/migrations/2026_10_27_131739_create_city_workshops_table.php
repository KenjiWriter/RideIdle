<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_workshops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('city', 64);                              // the city the workshop is in (a row is made when the first thing is bought there)
            $table->unsignedTinyInteger('tire_machine')->default(0); // the levels of the equipment, 0 is the start state
            $table->unsignedTinyInteger('lift')->default(0);
            $table->unsignedTinyInteger('hoist')->default(0);
            $table->unsignedTinyInteger('toolkit')->default(0);
            $table->unsignedTinyInteger('bays')->default(1);         // how many cars are worked on at once
            $table->unsignedTinyInteger('mechanics')->default(1);    // how many mechanics, as far as there are bays for them

            $table->timestamps();

            $table->unique(['user_id', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_workshops');
    }
};
