<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Run\Surface;
use App\Domain\Settlement\Ppm;
use App\Support\I18nText;

/**
 * The rules of a run (content/rules/starter.json): tracks, regional climate, weather effects and prices.
 * The shape is guaranteed by the schema and by StarterRulesTest, so this class only reads.
 */
final class Rules
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromConfig(): self
    {
        return self::fromFile(config('ride.content_path').'/rules/starter.json');
    }

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function fuelPriceCentsPerLiter(): int
    {
        return $this->data['fuel']['price_cents_per_liter'];
    }

    public function fuelReferencePowerKw(): int
    {
        return $this->data['fuel']['reference_power_kw'];
    }

    public function repairCostCents(): int
    {
        return $this->data['repair']['cost_cents'];
    }

    public function breakChancePerLapPpm(): int
    {
        return $this->data['breakdown']['chance_per_lap_ppm'];
    }

    /**
     * The chance of a breakdown after a lap for a car in a condition (1 000 000 is a perfect one): the base chance multiplied by
     * 1 + factor * (1 - condition), so a worn car breaks down more, and a perfect one as often as the base says.
     */
    public function breakChancePerLapPpmFor(int $conditionPpm): int
    {
        $worn = Ppm::ONE - max(0, min(Ppm::ONE, $conditionPpm));
        $multiplier = Ppm::ONE + $this->data['breakdown']['condition_factor'] * $worn;

        return min(Ppm::ONE, intdiv($this->breakChancePerLapPpm() * $multiplier + intdiv(Ppm::ONE, 2), Ppm::ONE));
    }

    /** How long a towed car waits before it drives again, in ms. */
    public function towDelayMs(): int
    {
        return $this->data['tow']['delay_ms'];
    }

    /** Laps of fuel a tow leaves in an empty tank. */
    public function towFuelLaps(): int
    {
        return $this->data['tow']['fuel_laps'];
    }

    public function stockTires(): string
    {
        return $this->data['stock_tires'];
    }

    /** The level a car has when the driver gets it. */
    public function carStartLevel(): int
    {
        return $this->data['car']['start_level'];
    }

    /** The experience a car gets for a lap, in any weather. */
    public function carXpPerLap(): int
    {
        return $this->data['car']['xp_per_lap'];
    }

    /** The level a car does not rise above. */
    public function carMaxLevel(): int
    {
        return $this->data['car']['max_level'];
    }

    /**
     * The experience that reaches a level of a car: the band from level n to n + 1 is xp_per_level * n, so this is
     * xp_per_level * n * (n - 1) / 2. Level 1 is the start, at nothing.
     */
    public function carXpToReach(int $level): int
    {
        return intdiv($this->data['car']['xp_per_level'] * $level * ($level - 1), 2);
    }

    /** The level that this much experience gives a car, never above the highest. */
    public function carLevelAt(int $xp): int
    {
        $level = 1;

        while ($level < $this->carMaxLevel() && $this->carXpToReach($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    /**
     * The value of each statistic at which its bar is full: what the best car of the game is meant to reach.
     *
     * @return array<string, float>
     */
    public function statCeilings(): array
    {
        return array_map('floatval', $this->data['car']['stat_ceiling']);
    }

    /**
     * The experience that reaches a level of the driver: xp_base * level ^ xp_exponent, to the whole. Level 1 is the start, at
     * nothing. The web draws the same curve for the bar of the HUD, and the economy simulator has its own copy of the numbers;
     * a test keeps the three together.
     */
    public function playerXpToReach(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        $player = $this->data['player'];

        return (int) round($player['xp_base'] * $level ** $player['xp_exponent']);
    }

    /** The level that this much experience gives a driver, never above the highest. */
    public function playerLevelAt(int $xp): int
    {
        $level = 1;

        while ($level < $this->data['player']['max_level'] && $this->playerXpToReach($level + 1) <= $xp) {
            $level++;
        }

        return $level;
    }

    /** The name of a city in the language of the driver. */
    public function cityName(string $region): string
    {
        return I18nText::from($this->data['regions'][$region]['name'])->for();
    }

    /** How many cars a garage holds. */
    public function garageCapacity(): int
    {
        return $this->data['garage']['capacity'];
    }

    /** The price of a garage in a city that is not the home city, in cents. */
    public function garagePriceCents(): int
    {
        return $this->data['garage']['price_cents'];
    }

    /** What a wreck costs in the yard, in cents, or null for a car that is not sold there. */
    public function wreckPriceCents(string $slug): ?int
    {
        return $this->data['garage']['wreck_price_cents'][$slug] ?? null;
    }

    /**
     * The used-car exchange: how often it is refreshed, how many cars it lists, and how a listed car is made (`market.exchange`).
     *
     * @return array<string, mixed>
     */
    public function marketExchange(): array
    {
        return $this->data['market']['exchange'];
    }

    /**
     * The formula that prices a used car (`market.pricing`).
     *
     * @return array<string, mixed>
     */
    public function marketPricing(): array
    {
        return $this->data['market']['pricing'];
    }

    /** How much longer a lap of a track that is not lit takes at night, in ppm of its time (1 030 000 is 3% longer). */
    public function nightLapPpm(): int
    {
        return $this->data['clock']['night_lap_ppm'];
    }

    /** @return array<string, array<string, mixed>> the regions of the rules: the cities of the map, with their climate */
    public function regions(): array
    {
        return $this->data['regions'];
    }

    /** The region of the first track of the rules: where a new driver starts, and where the map begins. */
    public function homeRegion(): string
    {
        return (string) $this->track($this->startingTrack())['region'];
    }

    /**
     * The tracks of a city, in the order of the rules: the first of them is the one a car drives on when it arrives.
     *
     * @return list<string>
     */
    public function tracksOf(string $region): array
    {
        return array_keys(array_filter($this->data['tracks'], static fn (array $track): bool => $track['region'] === $region));
    }

    /** The level of the driver from which a city is open: the lowest level of its tracks. */
    public function cityMinPlayerLevel(string $region): int
    {
        $levels = array_map(fn (string $track): int => $this->trackMinPlayerLevel($track), $this->tracksOf($region));

        return $levels === [] ? throw new \InvalidArgumentException("The city {$region} has no track.") : min($levels);
    }

    /** @return list<array{from: string, to: string, km: int}> */
    public function roads(): array
    {
        return $this->data['roads'];
    }

    /** The average speed of a journey as a share of the top speed of the car, in ppm. */
    public function travelSpeedSharePpm(): int
    {
        return $this->data['travel']['speed_share_ppm'];
    }

    /** The fuel a kilometre of the road takes from a car of the fuel use of the track, in ml. */
    public function travelFuelMlPerKm(): int
    {
        return $this->data['travel']['fuel_ml_per_km'];
    }

    /** The level of the driver from which a track is open. */
    public function trackMinPlayerLevel(string $slug): int
    {
        return $this->data['tracks'][$slug]['min_player_level'] ?? throw new \InvalidArgumentException("Unknown track {$slug}.");
    }

    /**
     * What the mass of a car does to its handling and braking: (reference mass / mass) ^ exponent. A heavy car is below 1, a light one
     * above, and a car of the reference mass is not touched.
     */
    public function massFactor(int $massKg): float
    {
        $mass = $this->data['car']['mass'];

        return ($mass['reference_kg'] / $massKg) ** $mass['exponent'];
    }

    /** The highest tier of a part that a car of this level can have mounted: 1 + floor(level / levels per tier). */
    public function maxMountableTier(int $carLevel): int
    {
        return 1 + intdiv($carLevel, $this->data['car']['levels_per_tier']);
    }

    /** The lowest level of a car that can have a part of this tier mounted. */
    public function requiredCarLevel(int $tier): int
    {
        return $this->data['car']['levels_per_tier'] * ($tier - 1);
    }

    /** @return list<string> slugs of the parts the shop sells */
    public function shopParts(): array
    {
        return $this->data['shop']['parts'];
    }

    /**
     * The mechanic of the workshop: the level and how much of the time of every job it cuts, in ppm.
     *
     * @return array{level: int, time_cut_ppm: int}
     */
    public function mechanic(): array
    {
        $workshop = $this->data['workshop'];

        return [
            'level' => $workshop['mechanic_level'],
            'time_cut_ppm' => min(Ppm::ONE - 1, $workshop['time_cut_ppm_per_level'] * $workshop['mechanic_level']),
        ];
    }

    /** How many jobs fit in the queue of the workshop, the one in progress included. */
    public function workshopMaxQueue(): int
    {
        return $this->data['workshop']['max_queue'];
    }

    /**
     * How long a job of the workshop takes, in ms: the time of the slot at tier 1 times the tier, cut by the level of the
     * mechanic, and a share of that for taking a part off. Whole numbers all the way, so the end of a job never depends on a machine.
     */
    public function workshopJobMs(string $slot, int $tier, bool $removal = false): int
    {
        $workshop = $this->data['workshop'];
        $base = $workshop['base_time_ms'][$slot] ?? throw new \InvalidArgumentException("The workshop has no time for the slot {$slot}.");

        $ms = Ppm::mul($base * $tier, Ppm::ONE - $this->mechanic()['time_cut_ppm']);

        return max(1, $removal ? Ppm::mul($ms, $workshop['removal_ppm']) : $ms);
    }

    /** @return array<string, array<string, mixed>> effect of every weather */
    public function weather(): array
    {
        return $this->data['weather'];
    }

    /**
     * How well a kind of tires grips on a surface, in ppm: the multiplier of the grip, the handling and the braking.
     *
     * @param  string  $kind  all_season, street, slick, rain, offroad or winter
     * @param  string  $surface  one of the surfaces of `Surface`
     */
    public function tireGripPpm(string $kind, string $surface): int
    {
        return $this->data['tire_matrix'][$kind][$surface] ?? throw new \InvalidArgumentException("The tire matrix has no {$kind} tires on {$surface}.");
    }

    /**
     * What a kind of tires multiplies on every surface, as numbers to show (1.0 is the grip of the tires on dry asphalt).
     *
     * @return array<string, float> by surface, in the order of `Surface::ALL`
     */
    public function tireSurfaces(string $kind): array
    {
        $surfaces = [];

        foreach (Surface::ALL as $surface) {
            $surfaces[$surface] = $this->tireGripPpm($kind, $surface) / Ppm::ONE;
        }

        return $surfaces;
    }

    /** @return list<string> the kinds of tires of the matrix */
    public function tireKinds(): array
    {
        return array_keys($this->data['tire_matrix']);
    }

    /** @return array<string, mixed>|null */
    public function region(string $slug): ?array
    {
        return $this->data['regions'][$slug] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function track(string $slug): ?array
    {
        return $this->data['tracks'][$slug] ?? null;
    }

    /** The track a new run starts on: the first one of the rules. */
    public function startingTrack(): string
    {
        return (string) array_key_first($this->data['tracks']);
    }

    /** @return array<string, array<string, mixed>> */
    public function tracks(): array
    {
        return $this->data['tracks'];
    }
}
