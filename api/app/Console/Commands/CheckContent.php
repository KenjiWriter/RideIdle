<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInAGame;
use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\FileContentSource;
use App\Content\PackValidationResult;
use App\Services\ContentPacks;
use Illuminate\Console\Command;

/**
 * What a deploy asks of the content: that the game can read it, and that it fits this version of the code. From files that is the whole content tree; from the
 * database it is the pack that is live (its documents against the schemas that were just deployed, and the checksum it was made with), and with --install the
 * pack of the release is made live first when there is none, which is how the database gets its first pack.
 */
final class CheckContent extends Command
{
    use RunsInAGame;

    protected $signature = 'content:check
        {--install : When the game reads from the database and no pack is live, make the pack of the content folder live}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Check that the content the game reads is whole and fits this version of the code (a deploy runs it)';

    public function handle(ContentPacks $packs): int
    {
        if (config('ride.content_source') !== 'database') {
            return $this->show($packs->validate(new FileContentSource((string) config('ride.content_path'))), 'The content files');
        }

        try {
            if ($packs->active() === null) {
                if (! $this->option('install')) {
                    $this->error('No content pack is live. Run content:check --install, or content:import --activate.');

                    return self::FAILURE;
                }

                $import = $packs->import(new FileContentSource((string) config('ride.content_path')), reason: 'installed by content:check', activate: true);
                $this->info("Pack {$import->pack->number} installed and made live.");
            }

            $pack = $packs->active();
            $source = $packs->sourceOf($pack);

            if ($source->checksum() !== $pack->checksum) {
                $this->error("The documents of the pack {$pack->number} are not the ones it was made with: the checksum is {$source->checksum()}, not {$pack->checksum}.");

                return self::FAILURE;
            }

            return $this->show($packs->validate($source), "The live pack {$pack->number}");
        } catch (ContentPackInvalid $problem) {
            return $this->show($problem->result, 'The content');
        } catch (ContentException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }
    }

    private function show(PackValidationResult $result, string $what): int
    {
        foreach ($result->errors() as $issue) {
            $this->error("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        foreach ($result->warnings() as $issue) {
            $this->warn("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        if (! $result->isValid()) {
            $this->error("{$what} does not fit this version of the game.");

            return self::FAILURE;
        }

        $this->info("{$what} is whole and fits this version of the game.");

        return self::SUCCESS;
    }
}
