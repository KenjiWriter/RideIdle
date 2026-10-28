<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A journey can be boosted (the car drives faster, burns more fuel and may be stopped by the police). Whether and when it is stopped is drawn when the journey
 * starts and kept here, so that it does not depend on when the driver looks; the client is told of it only when the stop has happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travels', function (Blueprint $table): void {
            $table->boolean('boosted')->default(false)->after('fuel_ml');

            // The moment the police stop the car, and the fine they ask (null: no stop).
            $table->bigInteger('police_at_ms')->nullable()->after('arrive_ms');
            $table->unsignedInteger('police_fine_cents')->nullable()->after('police_at_ms');

            // What the driver chose (pay, bribe, lawyer; default when the time ran out; waived when the journey was finished at once for coins), what came of it and what it cost.
            $table->string('police_choice', 12)->nullable()->after('police_fine_cents');
            $table->string('police_outcome', 16)->nullable()->after('police_choice');
            $table->unsignedInteger('police_paid_cents')->nullable()->after('police_outcome');
            $table->bigInteger('police_resolved_ms')->nullable()->after('police_paid_cents');
        });
    }

    public function down(): void
    {
        Schema::table('travels', function (Blueprint $table): void {
            $table->dropColumn(['boosted', 'police_at_ms', 'police_fine_cents', 'police_choice', 'police_outcome', 'police_paid_cents', 'police_resolved_ms']);
        });
    }
};
