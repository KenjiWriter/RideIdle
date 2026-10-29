<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInAGame;
use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Models\ContentPack;
use App\Services\ContentPacks;
use Illuminate\Console\Command;

/** Makes a pack of the database the one the game reads. Going back to an earlier pack is the same: the number of the earlier pack. */
final class ActivateContent extends Command
{
    use RunsInAGame;

    protected $signature = 'content:activate
        {number : The number of the pack}
        {--reason= : Why, kept with the activation}
        {--game= : The slug of the game (the default game when left out)}';

    protected $description = 'Make a pack of the database the one the game reads (also how to go back to an earlier one)';

    public function handle(ContentPacks $packs): int
    {
        $pack = ContentPack::query()->where('number', (string) $this->argument('number'))->first();

        if ($pack === null) {
            $this->error("There is no pack {$this->argument('number')}. content:status lists them.");

            return self::FAILURE;
        }

        try {
            $changed = $packs->activate($pack, $this->option('reason') ?: null);
        } catch (ContentPackInvalid $problem) {
            foreach ($problem->result->errors() as $issue) {
                $this->error("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
            }

            $this->error("The pack {$pack->number} does not pass the validation of this version of the game, so it is not made live.");

            return self::FAILURE;
        } catch (ContentException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        $this->info($changed ? "Pack {$pack->number} is the live one." : "Pack {$pack->number} is the live one already.");

        return self::SUCCESS;
    }
}
