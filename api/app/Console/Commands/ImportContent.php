<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\FileContentSource;
use App\Content\PackValidationResult;
use App\Services\ContentPacks;
use Illuminate\Console\Command;

/** Makes a pack in the database of the content of a folder (the `content/` tree), and with --activate makes it the pack the game reads. */
final class ImportContent extends Command
{
    protected $signature = 'content:import
        {path? : The content folder (default: the one of the configuration)}
        {--pack=core : The pack inside packs/ of the folder}
        {--number= : The number of the pack (default: the version in pack.json)}
        {--activate : Make it the pack the game reads}
        {--reason= : Why, kept with the activation}
        {--dry-run : Only check that the content can go in}
        {--if-none-active : Do nothing when a pack is live already (a deploy uses it to install the first one)}';

    protected $description = 'Make a pack of the content of a folder, and (with --activate) make it live';

    public function handle(ContentPacks $packs): int
    {
        if ($this->option('if-none-active') && $packs->active() !== null) {
            $this->info("A pack is live already: {$packs->active()?->number}. Nothing to do.");

            return self::SUCCESS;
        }

        $source = new FileContentSource((string) ($this->argument('path') ?: config('ride.content_path')), (string) $this->option('pack'));

        try {
            if ($this->option('dry-run')) {
                $result = $packs->validate($source);
                $this->report($result);

                return $result->isValid() ? self::SUCCESS : self::FAILURE;
            }

            $import = $packs->import($source, $this->option('number') ?: null, $this->option('reason') ?: null, (bool) $this->option('activate'));
        } catch (ContentPackInvalid $problem) {
            $this->report($problem->result);
            $this->error($problem->getMessage());

            return self::FAILURE;
        } catch (ContentException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        foreach ($import->warnings as $issue) {
            $this->warn("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        $this->info($import->created
            ? "Pack {$import->pack->number} made ({$import->pack->checksum}): {$import->newVersions} new version(s) of definitions."
            : "Pack {$import->pack->number} was there already with this content.");

        if ($this->option('activate')) {
            $this->info("Pack {$import->pack->number} is the live one.");
        }

        return self::SUCCESS;
    }

    private function report(PackValidationResult $result): void
    {
        foreach ($result->errors() as $issue) {
            $this->error("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        foreach ($result->warnings() as $issue) {
            $this->warn("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        if ($result->isValid()) {
            $counts = collect($result->counts())->map(fn (int $count, string $kind): string => "{$count} {$kind}")->implode(', ');
            $this->info("The content is valid ({$counts}), ".count($result->warnings()).' warning(s).');
        }
    }
}
