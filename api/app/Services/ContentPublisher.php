<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\ContentSource;
use App\Content\Definitions\JsonDocument;
use App\Content\PackContent;
use App\Content\PackValidationResult;
use App\Content\Publishing\CandidatePack;
use App\Content\Publishing\ContentBundle;
use App\Content\Publishing\DerivedTables;
use App\Content\Publishing\PackGates;
use App\Content\Publishing\PublicationRefused;
use App\Jobs\BuildContentPack;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Models\ContentPack;
use App\Models\ContentPublication;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The way from the drafts to a pack that is live (D5): a publisher asks for a pack, a job makes it out of the content as it is being worked on, and a publisher
 * makes it live. The job checks that nothing that the players use is taken out, validates the content, makes the derived tables again, runs the gates of the
 * simulators on it, and only then makes the pack (its number is the one that was given when it was asked for). A pack that fails any of it is not made, and the
 * report says what stopped it. Making it live (and going back to an earlier pack) is the activation of the packs, with its trail.
 */
final class ContentPublisher
{
    /** How many errors and warnings of the validation a report keeps. */
    private const ISSUES = 100;

    public function __construct(
        private readonly ContentPacks $packs,
        private readonly CandidatePack $candidates,
        private readonly DerivedTables $tables,
        private readonly PackGates $gates,
        private readonly ContentBundle $bundles,
        private readonly DefinitionUsage $usage,
        private readonly AdminAudit $audit,
    ) {}

    /**
     * What a pack made now would change against the pack that is live: the documents that are added, changed or leave, and the cars and parts that are withdrawn
     * or are on offer again. The tables are not counted: they are made again whatever the drafts say.
     *
     * @return list<array{key: string, change: string}>
     */
    public function changes(): array
    {
        $candidate = $this->candidates->make('working');
        $live = $this->packs->activeSource();
        $rows = [];

        foreach (array_unique([...$candidate->keys(), ...($live?->keys() ?? [])]) as $key) {
            if (str_starts_with($key, 'tables/')) {
                continue;
            }

            $new = $candidate->raw($key);
            $old = $live?->raw($key);

            $change = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                ContentSource::documentChecksum($old) !== ContentSource::documentChecksum($new) => 'changed',
                default => null,
            };

            if ($change !== null) {
                $rows[] = ['key' => $key, 'change' => $change];
            }
        }

        foreach (array_diff($candidate->withdrawn(), $live?->withdrawn() ?? []) as $slug) {
            $rows[] = ['key' => $slug, 'change' => 'withdrawn'];
        }

        foreach (array_diff($live?->withdrawn() ?? [], $candidate->withdrawn()) as $slug) {
            $rows[] = ['key' => $slug, 'change' => 'offered'];
        }

        return $rows;
    }

    /**
     * What the drafts would do to the game, without making a pack: the document of the drafts is judged, the derived tables are made again, the gates are run and the
     * economy is measured, and the same measure of the pack that is live is beside it, so that a change can be seen as a change. The measure of the live pack is kept
     * (it is the same until another pack is live), so the first preview is the slow one.
     *
     * @return array{errors: list<array{file: string, pointer: string, message: string}>, gates: list<array{name: string, passed: bool, ms: int, excerpt: string}>, now: array<string, mixed>, live: array<string, mixed>|null, changes: int}
     */
    public function preview(): array
    {
        // A request of the panel has a limit of seconds and the simulators take longer than that the first time; a process that has none (the console, the queue) is left alone.
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(180);
        }

        // The pack has the number that the next publication would have, so that its manifest is judged as a real one is.
        $candidate = $this->candidates->make($this->nextNumber());
        $validation = $this->packs->validate($candidate, checkTables: false);

        if (! $validation->isValid()) {
            return ['errors' => array_slice($validation->errors(), 0, self::ISSUES), 'gates' => [], 'now' => [], 'live' => null, 'changes' => count($this->changes())];
        }

        $candidate = $candidate->with($this->tables->make($candidate));
        $live = $this->packs->activeSource();

        return [
            'errors' => [],
            'gates' => $this->gates->run($candidate),
            'now' => $this->gates->measure($candidate),
            'live' => $live === null ? null : Cache::remember('studio.live-measure.'.$live->number(), 3600, fn (): array => $this->gates->measure($live)),
            'changes' => count($this->changes()),
        ];
    }

    /**
     * Asks for a pack to be made of the drafts. It is built by a job, so this returns before the pack is there.
     *
     * @throws PublicationRefused busy (another one is being made) or nothing_to_publish
     */
    public function queue(Admin $admin, ?string $note = null): ContentPublication
    {
        if (ContentPublication::query()->whereIn('status', [ContentPublication::STATUS_QUEUED, ContentPublication::STATUS_RUNNING])->exists()) {
            throw new PublicationRefused('busy');
        }

        if ($this->changes() === []) {
            throw new PublicationRefused('nothing_to_publish');
        }

        try {
            $publication = ContentPublication::query()->create([
                'number' => $this->nextNumber(),
                'status' => ContentPublication::STATUS_QUEUED,
                'requested_by' => $admin->id,
                'note' => $note === null || trim($note) === '' ? null : Str::limit(trim($note), 200, ''),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two publishers asked at once, and the other one got the number.
            throw new PublicationRefused('busy');
        }

        $this->audit->record('content_publication_queued', $admin, "content_publication:{$publication->number}");

        BuildContentPack::dispatch($publication->id);

        return $publication;
    }

    /** Builds the pack of a publication: what the job does. A pack that is made is ready; anything that stops it is in the report. */
    public function build(ContentPublication $publication): void
    {
        $publication->forceFill(['status' => ContentPublication::STATUS_RUNNING, 'started_at' => now()])->save();

        $report = ['steps' => [], 'errors' => [], 'warnings' => [], 'changes' => $this->changes(), 'tables' => [], 'gates' => [], 'failed_step' => null];

        try {
            $number = $publication->number;
            $candidate = $this->step($report, 'collect', fn (): PackContent => $this->candidates->make($number));

            $guards = new PackValidationResult;
            $this->step($report, 'guards', fn () => $this->guards($candidate, $this->packs->activeSource(), $guards));

            if (! $guards->isValid()) {
                $this->stop($publication, $report, 'guards', $guards);

                return;
            }

            $validation = $this->step($report, 'validate', fn (): PackValidationResult => $this->packs->validate($candidate, checkTables: false));

            if (! $validation->isValid()) {
                $this->stop($publication, $report, 'validate', $validation);

                return;
            }

            $report['warnings'] = array_slice($validation->warnings(), 0, self::ISSUES);

            $made = $this->step($report, 'tables', fn (): array => $this->tables->make($candidate));

            foreach ($made as $key => $json) {
                $before = $candidate->raw($key);
                $report['tables'][$key] = $before !== null && ContentSource::documentChecksum($before) === ContentSource::documentChecksum($json) ? 'same' : 'changed';
            }

            $candidate = $candidate->with($made);

            $report['gates'] = $this->step($report, 'gates', fn (): array => $this->gates->run($candidate));

            if (array_filter($report['gates'], static fn (array $gate): bool => ! $gate['passed']) !== []) {
                $this->stop($publication, $report, 'gates');

                return;
            }

            $import = $this->step($report, 'make', fn () => $this->packs->import($candidate, $number, "publication {$number}"));
        } catch (ContentPackInvalid $problem) {
            $this->stop($publication, $report, (string) $report['_step'], $problem->result);

            return;
        } catch (Throwable $problem) {
            $report['errors'][] = ['file' => '', 'pointer' => '', 'message' => Str::limit($problem->getMessage(), 500, '...')];
            $this->stop($publication, $report, (string) $report['_step']);

            return;
        }

        unset($report['_step']);

        $publication->forceFill(['status' => ContentPublication::STATUS_READY, 'content_pack_id' => $import->pack->id, 'finished_at' => now(), 'report' => $report])->save();

        $this->audit->record('content_publication_ready', $publication->requestedBy, "content_publication:{$publication->number}");
    }

    /** Ends a publication that did not get anywhere, with what is known about it. */
    public function fail(ContentPublication $publication, string $step, string $message): void
    {
        $report = $publication->report ?? ['steps' => [], 'errors' => [], 'warnings' => [], 'changes' => [], 'tables' => [], 'gates' => [], 'failed_step' => null];
        $report['errors'][] = ['file' => '', 'pointer' => '', 'message' => Str::limit($message, 500, '...')];

        $this->stop($publication, $report, $step);
    }

    /**
     * Makes a pack live, and says so to the trail (also when it is refused). A publisher does this for a pack that a publication made, and to go back to an earlier
     * one.
     *
     * @return bool false when it already was the live one
     *
     * @throws ContentPackInvalid
     */
    public function activate(ContentPack $pack, Admin $admin): bool
    {
        $previous = $this->packs->active();

        try {
            $changed = $this->packs->activate($pack, 'panel');
        } catch (ContentPackInvalid $problem) {
            $this->audit->record('content_pack_refused', $admin, "content_pack:{$pack->number}", ['errors' => array_slice(array_map(fn (array $issue): string => "{$issue['file']} {$issue['pointer']}: {$issue['message']}", $problem->result->errors()), 0, 10)]);

            throw $problem;
        }

        if ($changed) {
            $this->audit->record('content_pack_activated', $admin, "content_pack:{$pack->number}", ['previous' => $previous?->number]);
        }

        return $changed;
    }

    /** The pack as a file that can travel to another environment. */
    public function export(ContentPack $pack): string
    {
        return $this->bundles->export($this->packs->sourceOf($pack), $pack->number);
    }

    /**
     * Makes a pack of a bundle that was made in another environment (and, when asked, makes it live). The pack is checked as any pack is, tables included, and the
     * number that it came with is used unless another is given; a number that is taken by other content is refused.
     *
     * @throws ContentException when the file is not a bundle, or the number is taken by other content
     * @throws ContentPackInvalid when the content does not pass the validation
     */
    public function import(Admin $admin, string $bundle, bool $activate = false, ?string $number = null): ContentImport
    {
        $content = $this->bundles->read($bundle);
        $import = $this->packs->import($content, $number === null || trim($number) === '' ? $content->number() : trim($number), 'bundle');

        $this->audit->record('content_pack_imported', $admin, "content_pack:{$import->pack->number}", ['created' => $import->created, 'new_versions' => $import->newVersions]);

        if ($activate) {
            $this->activate($import->pack, $admin);
        }

        return $import;
    }

    /**
     * What the players use must not be taken out of the pack that is live: the cities, the tracks and the steps of the tutorial that they are in, and the cars and
     * the parts that they have. The drafts were checked when they were saved, but the players have moved since.
     */
    private function guards(PackContent $candidate, ?PackContent $live, PackValidationResult $result): void
    {
        if ($live === null) {
            return;
        }

        foreach (['starter', 'tutorial'] as $name) {
            $key = "rules/{$name}";

            if (! $live->has($key) || ! $candidate->has($key)) {
                continue;
            }

            foreach ($this->usage->removedInUse($name, JsonDocument::decode((string) $live->raw($key)), JsonDocument::decode((string) $candidate->raw($key))) as $what) {
                $result->addError("{$key}.json", '', "It takes out what players use: {$what}.");
            }
        }

        foreach (['vehicles' => ContentDefinition::KIND_VEHICLE, 'parts' => ContentDefinition::KIND_PART] as $folder => $kind) {
            foreach ($live->slugs($folder) as $slug) {
                if ($candidate->has("{$folder}/{$slug}")) {
                    continue;
                }

                $definition = ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->first();
                $used = $definition === null ? [] : $this->usage->instances($definition);

                if ($used !== []) {
                    $result->addError("{$folder}/{$slug}.json", '', "It leaves the pack, but players have it ({$this->describe($used)}).");
                }
            }
        }
    }

    /** @param  array<string, int>  $counts */
    private function describe(array $counts): string
    {
        return implode(', ', array_map(static fn (int $count, string $what): string => "{$count} {$what}", $counts, array_keys($counts)));
    }

    /**
     * Does a step, and writes down how long it took.
     *
     * @template T
     *
     * @param  array<string, mixed>  $report
     * @param  callable(): T  $do
     * @return T
     */
    private function step(array &$report, string $key, callable $do): mixed
    {
        $started = hrtime(true);
        $report['_step'] = $key;

        try {
            return $do();
        } finally {
            $report['steps'][] = ['key' => $key, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function stop(ContentPublication $publication, array $report, string $step, ?PackValidationResult $result = null): void
    {
        if ($result !== null) {
            $report['errors'] = [...$report['errors'], ...array_slice($result->errors(), 0, self::ISSUES)];
            $report['warnings'] = $report['warnings'] === [] ? array_slice($result->warnings(), 0, self::ISSUES) : $report['warnings'];
        }

        unset($report['_step']);
        $report['failed_step'] = $step;

        $publication->forceFill(['status' => ContentPublication::STATUS_FAILED, 'finished_at' => now(), 'report' => $report])->save();

        $this->audit->record('content_publication_failed', $publication->requestedBy, "content_publication:{$publication->number}", ['step' => $step]);
    }

    /** The next number of the month: a number is for one pack, and one that was asked for and failed is not used again. */
    private function nextNumber(): string
    {
        $prefix = now()->year.'.'.now()->month;
        $last = -1;

        foreach ([...ContentPack::query()->pluck('number'), ...ContentPublication::query()->pluck('number')] as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\.([0-9]+)$/', (string) $number, $found) === 1) {
                $last = max($last, (int) $found[1]);
            }
        }

        return $prefix.'.'.($last + 1);
    }
}
