<?php

declare(strict_types=1);

namespace App\Models;

use App\Content\Definitions\JsonDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A thing of the game that the content describes (a car, a part, the rules, a table): its identity, which the versions of its document hang on, its place in
 * the life of a definition, and the draft of its next document.
 */
class ContentDefinition extends Model
{
    public const KIND_VEHICLE = 'vehicle';

    public const KIND_PART = 'part';

    /** The rules of the game and the steps of the tutorial: single documents (`starter`, `tutorial`), whose parts are edited as sections. */
    public const KIND_RULES = 'rules';

    /** Never published: only a draft. */
    public const STATUS_DRAFT = 'draft';

    /** In the packs and on offer. */
    public const STATUS_ACTIVE = 'active';

    /** Still in the packs, so that what players own works, but no longer on offer. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Out of the packs: only a definition that nothing uses. */
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_WITHDRAWN, self::STATUS_ARCHIVED];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['draft_saved_at' => 'datetime'];
    }

    /** @return HasMany<ContentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    /** @return HasOne<ContentVersion, $this> the latest version of the document, which is the one a new pack names */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ContentVersion::class)->latestOfMany('number');
    }

    /** @return BelongsTo<Admin, $this> */
    public function draftAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'draft_admin_id');
    }

    public function hasDraft(): bool
    {
        return $this->draft !== null;
    }

    /**
     * The document of the latest version, decoded, or null when the definition has never been published.
     *
     * @return array<string, mixed>|null
     */
    public function publishedDocument(): ?array
    {
        $text = $this->latestVersion?->document;

        return $text === null ? null : JsonDocument::decode($text);
    }

    /**
     * The document being prepared, decoded, or null when there is no draft.
     *
     * @return array<string, mixed>|null
     */
    public function draftDocument(): ?array
    {
        return $this->draft === null ? null : JsonDocument::decode($this->draft);
    }

    /**
     * What there is to work on: the draft, or the latest published document when there is no draft.
     *
     * @return array<string, mixed>
     */
    public function workingDocument(): array
    {
        return $this->draftDocument() ?? $this->publishedDocument() ?? [];
    }

    /** The name in the language, in English when the language has none, or the slug when the document has no name. */
    public function title(string $locale = 'pl'): string
    {
        $name = $this->workingDocument()['name'] ?? [];

        return (string) ($name[$locale] ?? $name['en'] ?? $this->slug);
    }
}
