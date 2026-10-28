<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job that a step of the tutorial arranged: the first merge of a driver cannot fail, and the job remembers that it was queued under that arrangement, because
 * it is settled later, when the mechanic is done, and the step may be over by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->boolean('scripted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_jobs', function (Blueprint $table): void {
            $table->dropColumn('scripted');
        });
    }
};
