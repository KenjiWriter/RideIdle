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
            // 1 common, 2 uncommon, 3 rare, 4 epic, 5 legendary (`App\Domain\Run\Rarity`): the parts of the shop and the ones from before are common.
            $table->unsignedTinyInteger('rarity')->default(1)->after('slot');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('rarity');
        });
    }
};
