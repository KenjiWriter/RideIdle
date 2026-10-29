<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which games an admin may work in (L4a). An admin with `all_games` (everybody who was there before there were games) may work in every game, and manages the
 * games and the other admins; any other has a row here for each game they may enter, with the role they have in it (none: their own role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->boolean('all_games')->default(true);
        });

        Schema::create('admin_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->unsignedBigInteger('game_id')->index();
            $table->string('role', 16)->nullable();   // editor or publisher in this game; null is the role of the admin
            $table->timestamps();

            $table->unique(['admin_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_games');

        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn('all_games');
        });
    }
};
