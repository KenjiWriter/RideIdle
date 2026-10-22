<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The balance of a driver. Every change goes through the ledger (ledger_entries) while the
 * wallet row is locked, so the balance is always the sum of the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->bigInteger('cash_cents')->default(0);
            $table->bigInteger('xp')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Money can never go below zero, whatever the application does. SQLite cannot add the constraint later.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_cash_cents_not_negative CHECK (cash_cents >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
