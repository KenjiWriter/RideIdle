<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\ContentException;
use App\Content\FileContentSource;
use App\Content\PackContent;
use App\Models\ContentDefinition;
use App\Services\ContentPacks;

/**
 * The pack that the drafts make: the content as it is being worked on (a draft over the published document, no archived definition), with the manifest of the
 * pack that is live under the number of the new one, and with the cars and parts that were withdrawn named in it. Nothing is stored here: the pack is only made
 * once it has passed everything.
 */
final class CandidatePack
{
    public function __construct(private readonly ContentPacks $packs) {}

    public function make(string $number): PackContent
    {
        return $this->packs->working(['pack' => $this->manifest($number)], $number);
    }

    /** The manifest as text, the way a pack keeps it. */
    private function manifest(string $number): string
    {
        $base = $this->packs->active()?->manifest ?? (new FileContentSource((string) config('ride.content_path')))->raw('pack')
            ?? throw new ContentException('There is no manifest to make the pack from: no pack is live and the content folder has no pack.json.');

        $manifest = json_decode($base, false, 512, JSON_THROW_ON_ERROR);
        $manifest->version = $number;
        unset($manifest->checksum, $manifest->withdrawn);

        $withdrawn = ContentDefinition::query()
            ->where('status', ContentDefinition::STATUS_WITHDRAWN)
            ->whereIn('kind', [ContentDefinition::KIND_VEHICLE, ContentDefinition::KIND_PART])
            ->orderBy('slug')
            ->pluck('slug')
            ->unique()
            ->values()
            ->all();

        if ($withdrawn !== []) {
            $manifest->withdrawn = $withdrawn;
        }

        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
