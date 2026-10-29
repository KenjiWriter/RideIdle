<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which game a row belongs to (L4a). The content (definitions, packs, activations, publications) and the accounts have it; everything that a player owns hangs on
 * the account, so it needs none. What was there is the first game (id 1), which is also what a row gets when nothing says otherwise. What was unique in the whole
 * database is unique in a game now: two games may have a part of the same slug, a driver of the same name, an account with the same e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['content_definitions', 'content_packs', 'content_activations', 'content_publications', 'users', 'account_identities'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedBigInteger('game_id')->default(1)->index();
            });
        }

        // The trail keeps the game that an action was done in, and what was done before there were games has none.
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('game_id')->nullable()->index();
        });

        Schema::table('content_definitions', function (Blueprint $table): void {
            $table->dropUnique(['kind', 'slug']);
            $table->unique(['game_id', 'kind', 'slug']);
        });

        Schema::table('content_packs', function (Blueprint $table): void {
            $table->dropUnique(['number']);
            $table->unique(['game_id', 'number']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropUnique(['name_key']);
            $table->unique(['game_id', 'email']);
            $table->unique(['game_id', 'name_key']);
        });

        Schema::table('account_identities', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'subject']);
            $table->unique(['game_id', 'provider', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::table('account_identities', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'provider', 'subject']);
            $table->unique(['provider', 'subject']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'email']);
            $table->dropUnique(['game_id', 'name_key']);
            $table->unique(['email']);
            $table->unique(['name_key']);
        });

        Schema::table('content_packs', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'number']);
            $table->unique(['number']);
        });

        Schema::table('content_definitions', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'kind', 'slug']);
            $table->unique(['kind', 'slug']);
        });

        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->dropColumn('game_id');
        });

        foreach (['content_definitions', 'content_packs', 'content_activations', 'content_publications', 'users', 'account_identities'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('game_id');
            });
        }
    }
};
