<?php

declare(strict_types=1);

namespace App\Content\Publishing;

use App\Content\BotProfiles;
use App\Content\Catalog;
use App\Content\ContentSource;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Run\RunFreezer;
use App\Services\ContentPacks;
use InvalidArgumentException;

/**
 * What a part does to a car and to its laps, before it is in a pack: the test car with the parts of the shop (the set that the pace of the rarities is made
 * with), with the part mounted, on every track, dry and in the rain, with the place among the bots and what an hour of driving pays.
 *
 * The part is the one of the content as it is being worked on (a draft, or one that was never in a pack). It is compared with the part that is live when it was
 * changed, and otherwise with the car without it: with the part of the shop set that it would replace, or with the stock part of the car when it is that part
 * itself. The bots are those of the pack that is live, because the table of the new pack is only made when the pack is.
 */
final class PartPreview
{
    public const CAR = 'rdzak-gt-4';

    /** @var array<string, string> the parts that the test car has, by slot: the shop set of the pace of the rarities */
    public const SHOP_SET = ['tires' => 'street-tires-t2', 'suspension' => 'sport-suspension-t1', 'brakes' => 'disc-brakes-t2', 'exhaust' => 'sport-exhaust-t2'];

    public function __construct(private readonly ContentPacks $packs) {}

    /**
     * @param  int  $rarity  1 common to 5 legendary
     * @return array{part: string, car: string, rarity: int, baseline: string, baseline_part: string|null, stats: array<string, array{before: float, after: float}>, tracks: array<string, array{dry: array{before: int, after: int}, rain: array{before: int|null, after: int|null}, position: array{before: int|null, after: int|null}, income: array{before: float, after: float}}>}
     *
     * @throws InvalidArgumentException when there is no such part or car, or the car cannot run on a track with the part
     */
    public function preview(string $partSlug, string $carSlug = self::CAR, int $rarity = 1): array
    {
        $working = $this->packs->working();
        $live = $this->packs->activeSource();
        $catalog = Catalog::fromSource($working);
        $rules = Rules::fromSource($working);
        $profiles = BotProfiles::fromSource($live ?? $working);

        $part = $catalog->part($partSlug) ?? throw new InvalidArgumentException("There is no part {$partSlug}.");
        $catalog->vehicle($carSlug) ?? throw new InvalidArgumentException("There is no car {$carSlug}.");

        $after = new RunInputsFactory($catalog, $rules, $profiles);
        $published = $live?->raw("parts/{$partSlug}");
        $changed = $published !== null && ContentSource::documentChecksum($published) !== ContentSource::documentChecksum((string) $working->raw("parts/{$partSlug}"));
        $before = $changed ? new RunInputsFactory(Catalog::fromSource($working->with(["parts/{$partSlug}" => $published])), $rules, $profiles) : $after;

        $shop = [];

        foreach (self::SHOP_SET as $slot => $slug) {
            if ($catalog->part($slug) !== null) {
                $shop[$slot] = ['slug' => $slug, 'rarity' => 1];
            }
        }

        $slot = (string) $part['slot'];
        $withPart = [...$shop, $slot => ['slug' => $partSlug, 'rarity' => max(1, min(5, $rarity))]];
        $without = $shop;
        $baselinePart = $shop[$slot]['slug'] ?? null;

        if ($changed) {
            $without = $withPart;
            $baseline = 'published';
            $baselinePart = $partSlug;
        } elseif ($baselinePart === $partSlug) {
            unset($without[$slot]);
            $baseline = 'stock';
            $baselinePart = null;
        } else {
            $baseline = $baselinePart === null ? 'stock' : 'shop';
        }

        $stats = [];

        foreach ($after->stats($carSlug, $withPart)->stats as $stat => $value) {
            $stats[$stat] = ['before' => round($before->stats($carSlug, $without)->stats[$stat] ?? 0.0, 1), 'after' => round($value, 1)];
        }

        $tracks = [];

        foreach (array_keys($rules->tracks()) as $track) {
            $was = $this->measure($before, $carSlug, $track, $without);
            $is = $this->measure($after, $carSlug, $track, $withPart);

            $tracks[$track] = [
                'dry' => ['before' => $was['dry'], 'after' => $is['dry']],
                'rain' => ['before' => $was['rain'], 'after' => $is['rain']],
                'position' => ['before' => $was['position'], 'after' => $is['position']],
                'income' => ['before' => $was['income'], 'after' => $is['income']],
            ];
        }

        return ['part' => $partSlug, 'car' => $carSlug, 'rarity' => max(1, min(5, $rarity)), 'baseline' => $baseline, 'baseline_part' => $baselinePart, 'stats' => $stats, 'tracks' => $tracks];
    }

    /**
     * The lap dry and in the rain (when the city has rain), the place among the bots, and what an hour of dry laps pays in cents.
     *
     * @param  array<string, array{slug: string, rarity: int}>  $mounted
     * @return array{dry: int, rain: int|null, position: int|null, income: float}
     */
    private function measure(RunInputsFactory $factory, string $car, string $track, array $mounted): array
    {
        $config = (new RunFreezer)->freeze($factory->make($car, $track, $mounted), 'part-preview', 0)->config;

        return [
            'dry' => $config['lap_ms']['dry'],
            'rain' => $config['lap_ms']['rain'] ?? null,
            'position' => $config['field']['position'] ?? null,
            'income' => round($config['lap_cash_cents']['dry'] / $config['lap_ms']['dry'] * 3_600_000, 1),
        ];
    }
}
