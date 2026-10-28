<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentException;
use App\Models\ContentPack;
use App\Services\ContentExporter;
use App\Services\ContentPacks;
use Illuminate\Console\Command;

/** Writes a pack of the database as the `content/` tree, with its number and the checksum of its content in pack.json. */
final class ExportContent extends Command
{
    protected $signature = 'content:export
        {path : The folder to write (it has to be empty)}
        {--number= : The pack to write (default: the live one)}
        {--force : Write over what is in the folder}';

    protected $description = 'Write a pack of the database as the content/ tree, to keep in the repository or to import on another machine';

    public function handle(ContentPacks $packs, ContentExporter $exporter): int
    {
        $pack = $this->option('number') === null
            ? $packs->active()
            : ContentPack::query()->where('number', (string) $this->option('number'))->first();

        if ($pack === null) {
            $this->error($this->option('number') === null ? 'No pack is live, so there is nothing to export. Give --number.' : "There is no pack {$this->option('number')}.");

            return self::FAILURE;
        }

        try {
            $files = $exporter->write($packs->sourceOf($pack), (string) $this->argument('path'), $pack->number, (bool) $this->option('force'));
        } catch (ContentException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        $this->info("Pack {$pack->number} ({$pack->checksum}) written: {$files} files in {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
