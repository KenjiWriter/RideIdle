<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentPackInvalid;
use App\Content\Definitions\DefinitionRefused;
use App\Content\PackValidationResult;
use App\Content\PackValidator;
use App\Models\Admin;
use App\Models\ContentDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The drafts of the definitions of cars and parts: a change is prepared here, next to the versions, and reaches the game only when a pack that has it is made
 * live. A draft is only kept when its document passes the schema, so that what is waiting is always something a pack can take; a draft that says what the
 * published document says is no draft. Every change is written to the trail.
 */
final class ContentDrafts
{
    /** The folder of the pack that holds a kind of definition. */
    private const FOLDERS = [ContentDefinition::KIND_VEHICLE => 'vehicles', ContentDefinition::KIND_PART => 'parts'];

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * Whether a document passes: its schema, its slug, and the rules about the kind. The warnings (a language that is missing) do not stop it.
     *
     * @param  array<string, mixed>  $document
     */
    public function validate(string $kind, array $document, ?string $slug = null): PackValidationResult
    {
        return PackValidator::fromConfig()->validateDefinition($this->folder($kind), self::encode($document), $slug);
    }

    /**
     * Starts a definition that has never been published: it is a draft until a pack makes it active. Its slug is for good, and not one that has been used,
     * whatever became of that definition.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws ContentPackInvalid when the document does not pass
     * @throws DefinitionRefused slug_taken
     */
    public function create(string $kind, array $document, Admin $admin): ContentDefinition
    {
        $result = $this->validate($kind, $document);

        if (! $result->isValid()) {
            throw new ContentPackInvalid($result);
        }

        $slug = (string) $document['slug'];

        if (ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->exists()) {
            throw new DefinitionRefused('slug_taken', ['slug' => $slug]);
        }

        $definition = DB::transaction(fn (): ContentDefinition => ContentDefinition::query()->create([
            'uid' => (string) Str::ulid(),
            'kind' => $kind,
            'slug' => $slug,
            'status' => ContentDefinition::STATUS_DRAFT,
            'draft' => self::encode($document),
            'draft_admin_id' => $admin->id,
            'draft_saved_at' => now(),
        ]));

        $this->audit->record('definition_created', $admin, "{$kind}:{$slug}");

        return $definition;
    }

    /**
     * Keeps a document as the draft of a definition. A document that is what the published one is leaves no draft at all.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws ContentPackInvalid when the document does not pass
     * @throws DefinitionRefused archived
     */
    public function save(ContentDefinition $definition, array $document, Admin $admin): ContentDefinition
    {
        if ($definition->status === ContentDefinition::STATUS_ARCHIVED) {
            throw new DefinitionRefused('archived', ['slug' => $definition->slug]);
        }

        $result = $this->validate($definition->kind, $document, $definition->slug);

        if (! $result->isValid()) {
            throw new ContentPackInvalid($result);
        }

        $before = $definition->workingDocument();
        $published = $definition->publishedDocument();

        // Compared by what they say: a number that was 20 and is 20.0 is the same number.
        if ($published !== null && $published == $document) {
            return $this->discard($definition, $admin);
        }

        $definition->forceFill(['draft' => self::encode($document), 'draft_admin_id' => $admin->id, 'draft_saved_at' => now()])->save();

        $changed = array_keys(array_filter($document + $before, fn (mixed $value, string $key): bool => ($before[$key] ?? null) != ($document[$key] ?? null), ARRAY_FILTER_USE_BOTH));

        $this->audit->record('definition_draft_saved', $admin, "{$definition->kind}:{$definition->slug}", $changed === [] ? [] : ['changed' => array_values($changed)]);

        return $definition;
    }

    /**
     * Throws a draft away: the definition is as it was published. A definition that was never published has nothing to go back to, so this refuses it; it is
     * deleted instead.
     *
     * @throws DefinitionRefused unpublished
     */
    public function discard(ContentDefinition $definition, Admin $admin): ContentDefinition
    {
        if ($definition->versions()->doesntExist()) {
            throw new DefinitionRefused('unpublished', ['slug' => $definition->slug]);
        }

        if ($definition->hasDraft()) {
            $definition->forceFill(['draft' => null, 'draft_admin_id' => null, 'draft_saved_at' => null])->save();
            $this->audit->record('definition_draft_discarded', $admin, "{$definition->kind}:{$definition->slug}");
        }

        return $definition;
    }

    /**
     * Deletes a definition that was never published. That is the only deletion there is: what was published stays, withdrawn or archived, because a pack and a
     * player may name it.
     *
     * @throws DefinitionRefused published
     */
    public function delete(ContentDefinition $definition, Admin $admin): void
    {
        if ($definition->versions()->exists() || $definition->status !== ContentDefinition::STATUS_DRAFT) {
            throw new DefinitionRefused('published', ['slug' => $definition->slug]);
        }

        $definition->delete();
        $this->audit->record('definition_deleted', $admin, "{$definition->kind}:{$definition->slug}");
    }

    private function folder(string $kind): string
    {
        return self::FOLDERS[$kind] ?? throw new DefinitionRefused('not_editable', ['kind' => $kind]);
    }

    /** @param  array<string, mixed>  $document */
    private static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
