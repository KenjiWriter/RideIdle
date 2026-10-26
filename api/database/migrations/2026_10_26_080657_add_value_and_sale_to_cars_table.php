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
            // What the car is worth, in cents, as it was when the driver got it: the price on the exchange, at the dealer, in the yard, or the value of
            // what came out of a storage unit. A sale pays a share of it, so that buying and selling again cannot make money. Null: not known.
            $table->unsignedBigInteger('value_cents')->nullable();
            // The car was sold: it is gone from the garage and from every list, but what it earned stays in the history.
            $table->timestamp('sold_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            $table->dropColumn(['value_cents', 'sold_at']);
        });
    }
};
