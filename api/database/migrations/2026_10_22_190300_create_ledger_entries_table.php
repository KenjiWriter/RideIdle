<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger: every change of a balance, never edited. The idempotency key makes a posting
 * happen at most once, even when the same request is repeated or processed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 16)->default('cash');
            $table->bigInteger('delta');
            $table->bigInteger('balance_after');
            $table->string('reason', 32);

            // A settlement may be deleted with its run; the money record stays.
            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();

            $table->string('idem_key', 128)->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
