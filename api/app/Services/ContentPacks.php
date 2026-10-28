<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\BotProfileTable;
use App\Content\Catalog;
use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\ContentSource;
use App\Content\FileContentSource;
use App\Content\PackContent;
use App\Content\PackValidationResult;
use App\Content\PackValidator;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Models\ContentActivation;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The packs of content in the database: a pack is made out of a content source (the files of the repository, or another pack that was exported), and the one
 * that was made live last is what the game reads.
 *
 * A definition keeps its versions for good; a pack names one version of each definition, so the packs share what did not change, an old pack is whole as long
 * as it exists, and going back to it is the pointer moved. A pack that was made does not change: its number is its own.
 */
final class ContentPacks
{
    /** The folder of the documents in the content tree => the kind of the definition in the database. */
    private const KINDS = ['vehicles' => 'vehicle', 'parts' => 'part', 'rules' => 'rules', 'tables' => 'table'];

    private const NUMBER = '/^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$/';

    /** The source the game reads from, by the configuration: the files of the repository, or the pack that is live. */
    public function configured(): ContentSource
    {
        if (config('ride.content_source') !== 'database') {
            return new FileContentSource((string) config('ride.content_path'));
        }

        return $this->activeSource() ?? throw new ContentException('No content pack is live. Import one with php artisan content:import --activate (a deploy does it the first time, with content:check --install).');
    }

    public function active(): ?ContentPack
    {
        $activation = ContentActivation::query()->latest('id')->first();

        return $activation === null ? null : ContentPack::query()->find($activation->content_pack_id);
    }

    public function activeSource(): ?PackContent
    {
        $pack = $this->active();

        return $pack === null ? null : $this->sourceOf($pack);
    }

    /** The documents of a pack, which are read from the database once and kept in the cache under the checksum of the pack (a pack never changes). */
    public function sourceOf(ContentPack $pack): PackContent
    {
        $documents = Cache::rememberForever("content-pack:{$pack->id}:{$pack->checksum}", fn (): array => $this->documentsOf($pack));

        return new PackContent($pack->number, $documents);
    }

    /**
     * Whether a source can go in: its documents against their schemas, what the rules point to, and (when asked) the table of the bot profiles against the
     * pipeline of the game, because the field of bots that a stale table makes is not the one the content was balanced with.
     */
    public function validate(ContentSource $source, bool $checkTables = true): PackValidationResult
    {
        $result = PackValidator::fromConfig()->validateSource($source);

        if ($result->isValid() && $checkTables) {
            $this->checkBotProfiles($source, $result);
        }

        return $result;
    }

    /**
     * Makes a pack of the content of a source, and (with `$activate`) makes it live. The pack is numbered by `pack.json`, or by `$number`; a number that is
     * already taken by the same content gives that pack back, and by other content is refused. A definition that did not change keeps its version.
     *
     * @throws ContentPackInvalid when the content does not pass the validation
     * @throws ContentException when the number is not a number, is taken by other content, or the checksum of the manifest is not the one of the content
     */
    public function import(ContentSource $source, ?string $number = null, ?string $reason = null, bool $activate = false, bool $checkTables = true): ContentImport
    {
        $result = $this->validate($source, $checkTables);

        if (! $result->isValid()) {
            throw new ContentPackInvalid($result);
        }

        $number ??= (string) $source->manifest()['version'];

        if (preg_match(self::NUMBER, $number) !== 1) {
            throw new ContentException("{$number} is not a number of a pack (year.month.n).");
        }

        $checksum = $source->checksum();
        $stated = $source->manifest()['checksum'] ?? null;

        if ($stated !== null && $stated !== $checksum) {
            throw new ContentException("The checksum in pack.json ({$stated}) is not the one of the content ({$checksum}): it was changed after it was exported. Export it again, or take the checksum out of pack.json.");
        }

        $import = DB::transaction(fn (): ContentImport => $this->store($source, $number, $checksum, $result->warnings()));

        if ($activate) {
            $this->activate($import->pack, $reason, checked: true);
        }

        return $import;
    }

    /**
     * Makes a pack the one the game reads. Its content has to pass the validation again, because the schemas and the pipeline of the game are those of the
     * code that runs now, not of the code the pack was made with.
     *
     * @return bool false when it already was the live one
     *
     * @throws ContentPackInvalid
     */
    public function activate(ContentPack $pack, ?string $reason = null, bool $checked = false): bool
    {
        if ($this->active()?->id === $pack->id) {
            return false;
        }

        if (! $checked) {
            $result = $this->validate($this->sourceOf($pack));

            if (! $result->isValid()) {
                throw new ContentPackInvalid($result);
            }
        }

        ContentActivation::query()->create(['content_pack_id' => $pack->id, 'reason' => $reason === null ? null : Str::limit($reason, 200, '')]);

        return true;
    }

    /**
     * @param  list<array{file: string, pointer: string, message: string}>  $warnings
     */
    private function store(ContentSource $source, string $number, string $checksum, array $warnings): ContentImport
    {
        $existing = ContentPack::query()->where('number', $number)->first();

        if ($existing !== null) {
            return $existing->checksum === $checksum
                ? new ContentImport($existing, false, 0, $warnings)
                : throw new ContentException("The pack {$number} already exists with other content ({$existing->checksum}, this one is {$checksum}). A number is for one content: change the version in pack.json or give --number.");
        }

        $versionIds = [];
        $added = 0;

        foreach ($source->keys() as $key) {
            [$folder, $slug] = explode('/', $key, 2);
            $raw = (string) $source->raw($key);
            $documentChecksum = ContentSource::documentChecksum($raw);

            $definition = ContentDefinition::query()->firstOrCreate(['kind' => self::KINDS[$folder], 'slug' => $slug], ['uid' => (string) Str::ulid()]);
            $version = $definition->versions()->where('checksum', $documentChecksum)->first();

            if ($version === null) {
                $version = $definition->versions()->create([
                    'number' => (int) $definition->versions()->max('number') + 1,
                    'document' => $raw,
                    'checksum' => $documentChecksum,
                    'note' => "pack {$number}",
                ]);
                $added++;
            }

            $versionIds[] = $version->id;
        }

        $pack = ContentPack::query()->create([
            'number' => $number,
            'checksum' => $checksum,
            'manifest' => (string) $source->raw('pack'),
            'report' => $warnings === [] ? null : json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $pack->versions()->attach($versionIds);

        return new ContentImport($pack, true, $added, $warnings);
    }

    /** @return array<string, string> the text of every document of the pack by key, the manifest as `pack` */
    private function documentsOf(ContentPack $pack): array
    {
        $rows = DB::table('content_pack_items')
            ->join('content_versions', 'content_versions.id', '=', 'content_pack_items.content_version_id')
            ->join('content_definitions', 'content_definitions.id', '=', 'content_versions.content_definition_id')
            ->where('content_pack_items.content_pack_id', $pack->id)
            ->get(['content_definitions.kind', 'content_definitions.slug', 'content_versions.document']);

        $folders = array_flip(self::KINDS);
        $documents = ['pack' => $pack->manifest];

        foreach ($rows as $row) {
            $documents["{$folders[$row->kind]}/{$row->slug}"] = $row->document;
        }

        return $documents;
    }

    /** The table of the bot profiles has to be the one the pipeline makes of this content: the bots drive around the laps that it names. */
    private function checkBotProfiles(ContentSource $source, PackValidationResult $result): void
    {
        $file = 'tables/bot-profiles.json';
        $json = $source->raw('tables/bot-profiles');

        if ($json === null) {
            $result->addWarning($file, '', 'There is no table of the bot profiles: the cars race alone.');

            return;
        }

        try {
            $catalog = Catalog::fromSource($source);
            $rules = Rules::fromSource($source);
            $made = (new BotProfileTable($catalog, $rules, new RunInputsFactory($catalog, $rules)))->compute();
        } catch (InvalidArgumentException $problem) {
            $result->addError($file, '', "The game cannot be run on this content, so the table of the bot profiles cannot be checked: {$problem->getMessage()}");

            return;
        }

        if (json_decode($json, true) != $made) {
            $result->addError($file, '', 'The table is not the one that this content makes: run php artisan economy:bot-profiles on it and import it again.');
        }
    }
}
