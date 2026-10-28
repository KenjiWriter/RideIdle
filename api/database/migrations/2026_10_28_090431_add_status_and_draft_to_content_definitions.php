<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The life of a definition and its draft (F4, step S3). A definition is a draft until it is first published, then active; it can be withdrawn from the offer (it
 * is still in the packs, so what players own works) and archived (no longer in the packs, which only a definition that nothing uses may be). A change is
 * prepared as a draft next to the versions, which only a pack makes live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_definitions', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('slug'); // draft, active, withdrawn or archived
            $table->longText('draft')->nullable()->after('status');          // the document being prepared, as the text of its JSON; null when there is none
            $table->foreignId('draft_admin_id')->nullable()->after('draft')->constrained('admins')->nullOnDelete();
            $table->timestamp('draft_saved_at')->nullable()->after('draft_admin_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_definitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draft_admin_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'draft', 'draft_saved_at']);
        });
    }
};
