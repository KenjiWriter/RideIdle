<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pictures of cars and parts: a picture is kept under the checksum of its bytes, so the same file is one row, a pack that names it keeps working when a newer
 * pack names another, and nothing is ever written over. The bytes are in the database (as base64, which every engine takes as text) and not on a disk, so the
 * backup of the database holds them, a bundle can carry them, and there is no folder for the web server to be told about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_images', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('kind', 4);
            $table->string('mime', 24);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->longText('data');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_images');
    }
};
