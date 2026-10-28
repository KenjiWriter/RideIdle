<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            // The affixes that the part was drawn with (`App\Domain\Market\Affixes`), as JSON: the key of each and its amount in ppm. Null for a part that has none: the
            // parts of the shop, the common ones, and the ones from before that `parts:roll-affixes` has not been run for.
            $table->text('affixes')->nullable()->after('rarity');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('affixes');
        });
    }
};
