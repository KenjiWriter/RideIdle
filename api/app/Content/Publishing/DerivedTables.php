<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\BotProfiles;
use App\Content\BotProfileTable;
use App\Content\Catalog;
use App\Content\GameUnits;
use App\Content\PackContent;
use App\Content\RarityPace;
use App\Content\Rules;
use App\Content\RunInputsFactory;

/**
 * The tables that commands make out of the rest of the content, made again for a pack that is about to be built, because a table that is not the one its content
 * makes is a field of bots, a pace of the rarities or a return of the storage units that the content was not balanced with. They are made in the order they
 * depend on each other: the bots are made from the cars and the tracks, and the pace of the rarities is the income of a car among those bots.
 */
final class DerivedTables
{
    /**
     * The tables of the content, as the text of their documents by key. The table of the Elo does not come from the content, so it is not here.
     *
     * @return array<string, string>
     */
    public function make(PackContent $content): array
    {
        $catalog = Catalog::fromSource($content);
        $rules = Rules::fromSource($content);

        $bots = (new BotProfileTable($catalog, $rules, new RunInputsFactory($catalog, $rules)))->computedJson();
        $withBots = $content->with(['tables/bot-profiles' => $bots]);

        $inputs = new RunInputsFactory($catalog, $rules, BotProfiles::fromSource($withBots));

        return [
            'tables/bot-profiles' => $bots,
            'tables/rarity-pace' => (new RarityPace($rules, $inputs))->computedJson(),
            'tables/unit-returns' => (new GameUnits($catalog, $rules))->computedJson(),
        ];
    }
}
