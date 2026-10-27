<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\RunFreezer;
use JsonException;

/**
 * The reference lap of every tier on every track, from the real pipeline: the table of the bot profiles (`content/tables/bot-profiles.json`) that the bots
 * of the race are made from (`BotProfiles`, `RunInputsFactory`).
 *
 * The reference of a tier on a track is the dry lap of the quickest stock car of the tier on it (the car with nothing but the stock tires, any
 * combustion car of the pack, whether it is on sale or not), with the tires that the track asks for where the stock ones are not (`reference_tires`
 * of the track: on gravel the stock tires make every car a second slower, so a field made around them would pay a bonus to whoever buys the right
 * ones). The bots drive around it (`bots.offsets_ppm` of the rules), so a stock car that is the
 * quickest of its tier is in the middle of the field, a tuned one is ahead of the bots that are slower than its parts, and a stock car of the tier that
 * does not suit the track is behind. The place of a car is among cars of its own tier, which is what the multiplier of the reward by the tier of the car
 * leaves alone. The table is the same on every machine, and a test pins that the committed one is what this class computes.
 */
final class BotProfileTable
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
    ) {}

    /**
     * The table.
     *
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $tracks = [];

        foreach (array_keys($this->rules->tracks()) as $track) {
            $byTier = [];
            $tires = $this->rules->trackReferenceTires($track);
            $mounted = $tires === null ? [] : ['tires' => $tires];

            foreach ($this->catalog->vehicles() as $slug => $vehicle) {
                if (($vehicle['powertrain'] ?? null) !== 'ice') {
                    continue;
                }

                $ms = (new RunFreezer)->freeze($this->inputs->make($slug, $track, $mounted), 'bot-profiles', 0)->config['lap_ms']['dry'];
                $byTier[$vehicle['tier']] = min($byTier[$vehicle['tier']] ?? $ms, $ms);
            }

            ksort($byTier);
            $tracks[$track] = $byTier;
        }

        return [
            'description' => 'The dry lap, in ms, of the quickest stock car of every tier on every track (with the tires the track asks for, where the stock ones are not): what the bots of the race drive around. Made by the command economy:bot-profiles.',
            'weather' => 'dry',
            'tracks' => $tracks,
        ];
    }

    /** The table as the file has it. */
    public function computedJson(): string
    {
        try {
            return json_encode($this->compute(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $problem) {
            throw new \RuntimeException('The table of the bot profiles cannot be written: '.$problem->getMessage(), 0, $problem);
        }
    }
}
