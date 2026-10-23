<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a driver is in the tutorial: one row for each step it has reached. The steps themselves are data of the
 * content (content/rules/tutorial.json), so a row names its step by key. A step with no row is not reached yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('step_key', 64);

            // active: waiting for the driver; done: completed and paid; skipped: left out, with no reward.
            $table->string('state', 16);

            $table->timestamp('started_at')->nullable();

            // The driver answered the messages of the step: the phone is put away, though the step may still wait.
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_progress');
    }
};
