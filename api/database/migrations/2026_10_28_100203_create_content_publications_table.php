<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The making of a pack out of the drafts (F4, step S5). A publication is asked for by a publisher, built by a job (the pack of the content as it is being worked
 * on, its derived tables made again, the gates of the simulators run on it) and ends as a pack that is ready to be made live, or with the report of what stopped it.
 * Its number is the number of the pack it makes, and is not used again whatever became of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('status', 16); // queued, running, ready or failed
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->foreignId('content_pack_id')->nullable()->constrained('content_packs')->nullOnDelete();
            $table->longText('report')->nullable(); // JSON: the steps, what was wrong, what changed, how the gates went
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publications');
    }
};
