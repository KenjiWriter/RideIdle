<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A part a driver owns: the instance of a part definition of the content pack. In the inventory it has no car;
 * mounted, it points to a car and holds the slot it sits in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Slug of the definition in the content pack, and its slot, kept here so that the database itself
            // refuses a second part in one slot of a car.
            $table->string('part_slug', 64);
            $table->string('slot', 16);

            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            // The slot while the part is mounted, null in the inventory. Unique with the car: one part per slot.
            // (Two nulls do not collide, on any of the databases.)
            $table->string('mounted_slot', 16)->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->unique(['car_id', 'mounted_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
