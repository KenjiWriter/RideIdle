<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The games that this installation runs (L4a). Each has its own content, its own players and its own domain; the one that exists already is the first (id 1), and
 * it answers on the domain of this installation, so that nothing about it changes until another game is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 32)->unique();      // raceidle: the name that scripts and the panel use
            $table->string('name', 64);                // the title of the game
            $table->string('domain')->nullable()->unique(); // the host that the game answers on
            $table->string('locale', 5)->default('en');     // the language a visitor gets when theirs is not one of the game
            $table->string('theme_color', 7)->default('#0e1013');
            $table->string('status', 12)->default('live');  // live or draft: a draft game answers to nobody but an admin who chose it
            $table->boolean('is_default')->default(false);  // the game that answers where nothing says which (a command, a test, a host nobody claims in development)
            $table->timestamps();
        });

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        DB::table('games')->insert([
            'id' => 1,
            'slug' => 'raceidle',
            'name' => 'RideIdle',
            'domain' => is_string($host) && $host !== '' ? $host : null,
            'locale' => 'en',
            'theme_color' => '#0e1013',
            'status' => 'live',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
