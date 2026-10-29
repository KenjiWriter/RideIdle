<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The icon of a game (L4b): the name of a picture in the store of images (`<sha256>.<png|webp|jpg>`), square, that the manifest of the app and the tab show; and
 * optionally the same drawn with a margin, for the systems that cut icons into shapes. A game without them keeps the icons of the first game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->string('icon', 70)->nullable();
            $table->string('icon_maskable', 70)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['icon', 'icon_maskable']);
        });
    }
};
