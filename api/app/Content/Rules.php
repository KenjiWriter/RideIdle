<?php

declare(strict_types=1);

namespace App\Content;

use App\Domain\Economy\PlayerXpCurve;
use App\Domain\Run\Rarity;
use App\Domain\Run\Surface;
use App\Domain\Settlement\Ppm;
use App\Domain\Workshop\WorkshopGear;
use App\Support\I18nText;
use InvalidArgumentException;

/**
 * The rules of a run (content/rules/starter.json): tracks, regional climate, weather effects and prices.
 * The shape is guaranteed by the schema and by StarterRulesTest, so this class only reads.
 */
final class Rules
{
    private ?PlayerXpCurve $playerCurve = null;

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromConfig(): self
    {
        return self::fromSource(app(ContentSource::class));
    }

    public static function fromSource(ContentSource $source): self
    {
        $data = $source->document('rules/starter');

        // A part that was withdrawn leaves the shop (and with it the crates, which draw from the shop), but the ones that players own run as before.
        if (isset($data['shop']['parts'])) {
            $data['shop']['parts'] = array_values(array_diff($data['shop']['parts'], $source->withdrawn()));
        }

        return new self($data);
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
     *
     * The parts and the service add to it (`wear.breakdown`): by 1 + 3 x the mean wear of the parts that sit on the car, by 1 + 2 x how far into its
     * overdue the service is, and by 1 + 1 for every part that is unserviceable. A car with fresh parts and a service in time breaks down as the
     * condition of the car says.
     *
     * @param  int  $meanWearPpm  the mean wear of the parts on the car, in ppm of their life
     * @param  int  $overdueSharePpm  how far into the overdue of the service the car is, as a share of the longest, in ppm
     * @param  int  $unserviceable  how many parts are unserviceable
     * @param  int  $reliabilityKeptPpm  what the affixes of reliability of the parts leave of the chance, in ppm (1 000 000 when no part has one)
     */
    public function breakChancePerLapPpmFor(int $conditionPpm, int $meanWearPpm = 0, int $overdueSharePpm = 0, int $unserviceable = 0, int $reliabilityKeptPpm = Ppm::ONE): int
    {
        $worn = Ppm::ONE - max(0, min(Ppm::ONE, $conditionPpm));
        $multiplier = Ppm::ONE + $this->data['breakdown']['condition_factor'] * $worn;
        $chance = min(Ppm::ONE, intdiv($this->breakChancePerLapPpm() * $multiplier + intdiv(Ppm::ONE, 2), Ppm::ONE));

        $wear = $this->data['wear']['breakdown'];

        foreach ([
            Ppm::ONE + $wear['wear_factor'] * $meanWearPpm,
            Ppm::ONE + $wear['overdue_factor'] * $overdueSharePpm,
            Ppm::ONE + $wear['unserviceable_factor'] * Ppm::ONE * $unserviceable,
        ] as $factor) {
            $chance = min(Ppm::ONE, Ppm::mul($chance, $factor));
        }

        // The affixes of reliability of the parts on the car take their share off what is left.
        return Ppm::mul($chance, $reliabilityKeptPpm);
    }

    /** What the odometer of a wreck of this car says, in km: the board of the wrecks (0 for a car that is not a wreck of the yard). */
    public function wreckMileageKm(string $slug): int
    {
        return $this->data['garage']['wreck_mileage_km'][$slug] ?? 0;
    }

    /** What the odometer of a new car of the dealer says, in km. */
    public function dealerMileageKm(): int
    {
        return $this->data['market']['dealer_mileage_km'];
    }

    /** How long a lap of a track is, in metres: what the odometer and the wear of the parts grow by with every lap. */
    public function trackLengthM(string $slug): int
    {
        return $this->data['tracks'][$slug]['length_m'] ?? throw new InvalidArgumentException("Unknown track {$slug}.");
    }

    /**
     * What a kilometre wears a part of this slot by, in billionths of its life: 1 / life of the slot, times what the ground does to it (rough_ppm on
     * gravel), times (power / reference power) ^ exponent for the power of the car (never under 0.8 or over 2), times what the load of the engine does to the
     * parts of the engine (1% for every point of load, see `engineLoad`). The only place where this is a floating point number; it is rounded and frozen with
     * the rest of the configuration.
     *
     * @param  int  $engineLoad  the load of the engine of the car, never under 0
     */
    public function wearPerKmPpb(string $slot, string $surface, float $powerKw, int $engineLoad = 0): int
    {
        $wear = $this->data['wear'];
        $factor = ($surface === 'gravel' ? ($wear['rough_ppm'][$slot] ?? Ppm::ONE) : Ppm::ONE) / Ppm::ONE;
        $factor *= max(0.8, min(2.0, ($powerKw / $this->data['fuel']['reference_power_kw']) ** ($wear['power_exponent_ppm'] / Ppm::ONE)));

        if ($engineLoad > 0 && in_array($slot, ['engine', 'boost', 'gearbox', 'cooling'], true)) {
            $factor *= 1 + $engineLoad * $wear['load_ppm_per_point'] / Ppm::ONE;
        }

        return (int) round(1_000_000_000 / $wear['life_km'][$slot] * $factor);
    }

    /** The odometer, the steps of the condition and the service of the rules, for the plan of a car. */
    public function wearStagePpb(): int
    {
        return $this->data['wear']['stage_ppm'] * 1000;
    }

    /** The condition of a part that is this worn, rounded down to the stage it is in: what the frozen configuration of a run sees, in ppm. */
    public function stagedConditionPpm(int $wearPpb): int
    {
        $stage = $this->wearStagePpb();

        return max(0, Ppm::ONE - intdiv(min(1_000_000_000, intdiv(max(0, $wearPpb), $stage) * $stage), 1000));
    }

    /** What a part gives of its statistics at a condition: the floor (60%) and the rest in proportion to the condition. */
    public function conditionStatPpm(int $conditionPpm): int
    {
        $floor = $this->data['wear']['stat_floor_ppm'];

        return $floor + Ppm::mul(Ppm::ONE - $floor, max(0, min(Ppm::ONE, $conditionPpm)));
    }

    /** Under this condition a part is unserviceable, in ppm. */
    public function unserviceablePpm(): int
    {
        return $this->data['wear']['unserviceable_ppm'];
    }

    /** The service of the rules: the interval, the steps of its overdue and what they cost, as the plan of a car needs them. */
    public function service(): array
    {
        return $this->data['wear']['service'];
    }

    /** What the repair of a part costs: half of its price for the whole condition it is short of (the design: price x (1 - condition) x 0.5). */
    public function partRepairCostCents(int $priceCents, int $conditionPpm): int
    {
        return Ppm::mul(Ppm::mul($priceCents, $this->data['wear']['repair_cost_ppm_of_price']), Ppm::ONE - max(0, min(Ppm::ONE, $conditionPpm)));
    }

    /** How long the repair of a part takes the mechanic: the time of putting it on for the whole condition it is short of (the design: base x (1 - condition)). */
    public function partRepairMs(string $slot, int $tier, int $conditionPpm, ?WorkshopGear $gear = null): int
    {
        return max(1, Ppm::mul($this->workshopJobMs($slot, $tier, gear: $gear), Ppm::ONE - max(0, min(Ppm::ONE, $conditionPpm))));
    }

    /** How many laps of a track it takes to drive the interval of the service (rounded up). */
    public function serviceIntervalLaps(int $lapLengthM): int
    {
        return intdiv($this->data['wear']['service']['every_km'] * 1000 + $lapLengthM - 1, $lapLengthM);
    }

    /**
     * What a service of a car costs: 4% of what it earns between two services, but not less than the minimum. The income is the reward of a lap that the
     * car is frozen with (the place among the bots and the class of the car counted) times the laps of the interval, so a car that earns more pays more
     * and the service is the same share of the income at every level, whatever the car cost.
     *
     * @param  int  $lapCashCents  what a lap of the car pays, in cents
     * @param  int  $lapLengthM  how long a lap of the track it drives is, in metres
     * @param  string|null  $drivetrain  the drive of the car, whose service costs more or less (a four wheel drive has more to look after); null is the reference drive
     */
    public function serviceCostCents(int $lapCashCents, int $lapLengthM, ?string $drivetrain = null): int
    {
        $service = $this->data['wear']['service'];
        $cost = max($service['min_cost_cents'], Ppm::mul($lapCashCents * $this->serviceIntervalLaps($lapLengthM), $service['cost_ppm_of_income']));

        return $drivetrain === null ? $cost : Ppm::mul($cost, $this->drivetrain($drivetrain)['service_ppm']);
    }

    /** How long a service takes the mechanic, cut by his level like any job, and by the toolkit of the workshop (it has no part, so no equipment of a slot). */
    public function serviceJobMs(?WorkshopGear $gear = null): int
    {
        return $this->withGear(max(1, Ppm::mul($this->data['wear']['service']['time_ms'], Ppm::ONE - $this->mechanic()['time_cut_ppm'])), $gear, null);
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

    /**
     * What a lap pays as a share of the reward of the track for a car of this tier, in ppm: the factor of the highest tier named in the rules that is
     * at most the tier of the car (the tiers that nothing names pay as the tier before them).
     */
    public function carRewardPpm(int $tier): int
    {
        $ppm = null;
        $named = 0;

        foreach ($this->data['car']['reward_ppm_from_tier'] as $from => $factor) {
            if ((int) $from <= $tier && (int) $from >= $named) {
                $named = (int) $from;
                $ppm = $factor;
            }
        }

        return $ppm ?? Ppm::ONE;
    }

    /**
     * The lap time of every bot against the reference of its tier on the track, in ppm (-90 000 is a bot 9% quicker), from the quickest to the slowest.
     *
     * @return list<int>
     */
    public function botOffsetsPpm(): array
    {
        return array_values($this->data['bots']['offsets_ppm']);
    }

    /**
     * What the reward of a lap is multiplied by for each place of the field, the first place first, in ppm.
     *
     * @return list<int>
     */
    public function botRewardPpmByPosition(): array
    {
        return array_values($this->data['bots']['reward_ppm_by_position']);
    }

    /**
     * The names of the teams that the bots are called by.
     *
     * @return list<string>
     */
    public function botNames(): array
    {
        return array_values($this->data['bots']['names']);
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
     * What share of the experience of the laps of a car that the driver does not play goes to the driver, in ppm (0 today: only the car that is played
     * levels the driver, the others earn cash and level themselves).
     */
    public function otherCarsXpPpm(): int
    {
        return $this->data['player']['other_cars_xp_ppm'];
    }

    /**
     * The experience that reaches a level of the driver, to the whole (see `PlayerXpCurve`: a table for the first levels, a straight
     * line to the catch-up level, and then xp_base * level ^ xp_exponent). Level 1 is the start, at nothing. The web draws the same curve
     * for the bar of the HUD, and the economy simulator has its own copy of the numbers; a test keeps the three together.
     */
    public function playerXpToReach(int $level): int
    {
        return (int) round($this->playerCurve()->xpToReach($level));
    }

    private function playerCurve(): PlayerXpCurve
    {
        $player = $this->data['player'];

        return $this->playerCurve ??= new PlayerXpCurve($player['xp_base'], $player['xp_exponent'], $player['early_xp'], $player['catch_up_level']);
    }

    /** How much of its first lap a car that is sent out for the first time has driven already, in ms: the first lap is short (Start of the tournament). */
    public function startHeadStartMs(): int
    {
        return $this->data['start']['head_start_ms'];
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

    /** What a driver is paid for a car, as a share of its value in ppm (the economy model has 70%). */
    public function marketResalePpm(): int
    {
        return $this->data['market']['resale_ppm'];
    }

    /** The least a car is bought for, in cents for every kilogram of its mass: what it is worth as scrap. */
    public function marketScrapCentsPerKg(): int
    {
        return $this->data['market']['scrap_cents_per_kg'];
    }

    /**
     * The storage units of the city: the guarantee, what can be in a unit, and the kinds of unit (`market.storage`).
     *
     * @return array<string, mixed>
     */
    public function marketStorage(): array
    {
        return $this->data['market']['storage'];
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

        return $levels === [] ? throw new InvalidArgumentException("The city {$region} has no track.") : min($levels);
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

    /** The slug of the tires that the reference car of the bots of a track has instead of the stock ones, or null when it has the stock ones. */
    public function trackReferenceTires(string $slug): ?string
    {
        return $this->data['tracks'][$slug]['reference_tires'] ?? null;
    }

    /** The level of the driver from which a track is open. */
    public function trackMinPlayerLevel(string $slug): int
    {
        return $this->data['tracks'][$slug]['min_player_level'] ?? throw new InvalidArgumentException("Unknown track {$slug}.");
    }

    /**
     * What the mass of a car does to its handling and braking: (reference mass / mass) ^ exponent. A heavy car is below 1, a light one
     * above, and a car of the reference mass is not touched.
     */
    public function massFactor(int|float $massKg): float
    {
        $mass = $this->data['car']['mass'];

        return ($mass['reference_kg'] / $massKg) ** $mass['exponent'];
    }

    /** What an engine of this tier makes of every litre, as a multiplier: 1 + 7.5% for every tier above the first (the rules `engines`). */
    public function engineTierEfficiency(int $tier): float
    {
        return 1.0 + $this->data['engines']['tier_efficiency_ppm_per_tier'] * (max(1, $tier) - 1) / Ppm::ONE;
    }

    /** The power of an engine in kW: what a litre of the layout makes, times the litres, times the efficiency of the tier. */
    public function enginePowerKw(string $layout, float $displacementL, int $tier): float
    {
        return $this->engineLayout($layout)['kw_per_litre'] * $displacementL * $this->engineTierEfficiency($tier);
    }

    /** What an engine weighs in kg: the mass of its layout and of every litre of it. */
    public function engineMassKg(string $layout, float $displacementL): float
    {
        $spec = $this->engineLayout($layout);

        return $spec['base_mass_kg'] + $spec['kg_per_litre'] * $displacementL;
    }

    /**
     * The layout of an engine (R4, V6, ...) as the rules give it.
     *
     * @return array{kw_per_litre: int|float, base_mass_kg: int|float, kg_per_litre: int|float}
     */
    public function engineLayout(string $layout): array
    {
        return $this->data['engines']['layouts'][$layout] ?? throw new InvalidArgumentException("The rules have no engine layout {$layout}.");
    }

    /**
     * What the bay of a body class takes: the layouts and the displacement it holds at the most. A class the rules do not name takes nothing.
     *
     * @return array{layouts: list<string>, max_displacement_l: int|float}|null
     */
    public function engineBay(string $class): ?array
    {
        return $this->data['engines']['bays'][$class] ?? null;
    }

    /**
     * What a drive (fwd, rwd or awd) does to a car, relative to the reference drive of the rules: the ratio of its row to the row of the reference, so the car with
     * the reference drive is multiplied by exactly 1 in everything and the others are better or worse than it by what the numbers of the design say. The factors
     * of the acceleration and of the handling are `start` and `handling`, the `mass` penalty multiplies both, `fuel` the fuel use, `service_ppm` the price of a
     * service, and `traction_ppm` the grip, the handling and the braking on each surface.
     *
     * @return array{start: float, handling: float, mass: float, fuel: float, service_ppm: int, traction_ppm: array<string, int>}
     */
    public function drivetrain(string $kind): array
    {
        $row = $this->drivetrainRow($kind);
        $reference = $this->drivetrainRow($this->data['drivetrain']['reference']);
        $relative = static fn (int $ppm, int $base): int => intdiv($ppm * Ppm::ONE + intdiv($base, 2), $base);
        $ratio = static fn (int $ppm, int $base): float => $ppm / $base;
        $traction = [];

        foreach ($row['traction_ppm'] as $surface => $ppm) {
            $traction[$surface] = $relative($ppm, $reference['traction_ppm'][$surface]);
        }

        return [
            'start' => $ratio($row['start_ppm'], $reference['start_ppm']),
            'handling' => $ratio($row['handling_ppm'], $reference['handling_ppm']),
            'mass' => $ratio($row['mass_penalty_ppm'], $reference['mass_penalty_ppm']),
            'fuel' => $ratio($row['fuel_ppm'], $reference['fuel_ppm']),
            'service_ppm' => $relative($row['service_ppm'], $reference['service_ppm']),
            'traction_ppm' => $traction,
        ];
    }

    /**
     * What the limit of a drive takes off the acceleration of a car of this power: nothing up to the power it can put on the road, and above it
     * (limit / power) ^ exponent. A front wheel drive spins its wheels with a lot of power under the bonnet, a rear wheel drive less so, and a four wheel drive
     * has no limit.
     */
    public function drivetrainPowerFactor(string $kind, float $powerKw): float
    {
        $limit = $this->drivetrainRow($kind)['power_limit'] ?? null;

        return $limit === null || $powerKw <= $limit['kw'] ? 1.0 : ($limit['kw'] / $powerKw) ** $limit['exponent'];
    }

    /** @return array<string, mixed> */
    private function drivetrainRow(string $kind): array
    {
        return $this->data['drivetrain']['types'][$kind] ?? throw new InvalidArgumentException("The rules have no drivetrain {$kind}.");
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
     * The slots a crate draws parts for: the parts of the other slots are sold in the shop, but are not in the pools of the crates.
     *
     * @return list<string>
     */
    public function crateSlots(): array
    {
        return $this->data['shop']['crates']['slots'];
    }

    /** What the lag of a booster leaves of the acceleration of the car (1 point of lag takes 1% off; never under nothing). */
    public function lagFactor(float $lag): float
    {
        return max(0.0, 1.0 - $lag * $this->data['car']['lag_ppm_per_point'] / Ppm::ONE);
    }

    /**
     * What a part of a rarity at +N multiplies what it adds by, in ppm: the rarity times 1 + 3% for every level of the upgrade (Systemy II, point 7:
     * a rare +7 is x1.22 x 1.21 = x1.48 of the model, a common +3 only x1.09).
     */
    public function statPpm(Rarity $rarity, int $upgrade = 0): int
    {
        $ppm = $this->data['shop']['rarity_stat_ppm'][$rarity->key()];

        return $upgrade === 0 ? $ppm : Ppm::mul($ppm, Ppm::ONE + $this->data['workshop']['upgrade']['stat_ppm_per_level'] * $upgrade);
    }

    /** How far a part of this rarity can be upgraded at the most, whatever the car. */
    public function upgradeMax(Rarity $rarity): int
    {
        return $this->data['workshop']['upgrade']['max_by_rarity'][$rarity->key()];
    }

    /** How far a part of this rarity can be upgraded on a car of this level: at most 1 + ⌊level / levels_per_upgrade⌋ (a car of level 3 gets +2, level 6 gets +4). */
    public function upgradeCap(Rarity $rarity, int $carLevel): int
    {
        return min($this->upgradeMax($rarity), 1 + intdiv($carLevel, $this->data['car']['levels_per_upgrade']));
    }

    /** The lowest level of a car on which a part can be upgraded to +N. */
    public function requiredCarLevelForUpgrade(int $to): int
    {
        return $this->data['car']['levels_per_upgrade'] * ($to - 1);
    }

    /**
     * What the step from +n to +n+1 of a part costs: 35% of the price of the model for the first step, and 45% more for every step after it.
     * Whole cents all the way, so the price never depends on a machine.
     */
    public function upgradeCostCents(int $priceCents, int $from): int
    {
        $upgrade = $this->data['workshop']['upgrade'];
        $cost = Ppm::mul($priceCents, $upgrade['cost_ppm_of_price']);

        for ($step = 0; $step < $from; $step++) {
            $cost = Ppm::mul($cost, $upgrade['cost_growth_ppm']);
        }

        return $cost;
    }

    /**
     * How long the step from +n to +n+1 takes the mechanic: 5 minutes for the first, 40% more for every step after it, cut by the level of the mechanic, and
     * by the equipment of the workshop for the slot of the part (`$slot`) and its toolkit.
     */
    public function upgradeJobMs(int $from, ?WorkshopGear $gear = null, ?string $slot = null): int
    {
        $upgrade = $this->data['workshop']['upgrade'];
        $ms = $upgrade['base_time_ms'];

        for ($step = 0; $step < $from; $step++) {
            $ms = Ppm::mul($ms, $upgrade['time_growth_ppm']);
        }

        return $this->withGear(max(1, Ppm::mul($ms, Ppm::ONE - $this->mechanic()['time_cut_ppm'])), $gear, $slot);
    }

    /**
     * The chance that an attempt of merging makes the part of the rarity `$to`, in ppm: the base of that rarity, 1 percentage point for every level of
     * the mechanic (the level of the workshop), and 3 for every failure since the last success at that slot and rarity (the protection from bad luck),
     * but never more than 90%.
     */
    public function mergeChancePpm(Rarity $to, int $failures): int
    {
        $merge = $this->data['workshop']['merge'];
        $chance = $merge['base_ppm'][$to->key()] + $merge['level_ppm'] * $this->data['workshop']['mechanic_level'] + $merge['pity_ppm'] * $failures;

        return max(0, min($merge['max_ppm'], $chance));
    }

    /** What an attempt of merging costs: a tenth of the price of the model. */
    public function mergeCostCents(int $priceCents): int
    {
        return Ppm::mul($priceCents, $this->data['workshop']['merge']['cost_ppm_of_price']);
    }

    /** How long an attempt of merging takes the mechanic: twice what putting the part on takes, in ms. The parts are not on a car, so only the toolkit of the workshop speeds it up. */
    public function mergeJobMs(string $slot, int $tier, ?WorkshopGear $gear = null): int
    {
        return max(1, Ppm::mul($this->workshopJobMs($slot, $tier, gear: $gear, slotEquipment: false), $this->data['workshop']['merge']['time_ppm_of_mount']));
    }

    /** The scrap that a failed attempt gives back: a quarter of the price of the model. */
    public function mergeScrapCents(int $priceCents): int
    {
        return Ppm::mul($priceCents, $this->data['workshop']['merge']['scrap_ppm_of_price']);
    }

    /**
     * The statistics of a part of a rarity at +N and in a condition: what the part adds to a car is multiplied (grip, handling, braking, clearance), and so is the
     * power of an engine (`power_kw`); of a multiplier of the power only the gain is (1.02 at x1.6 is 1.032). A limit, like the one of a gearbox, and the mass
     * of an engine are not gains and stay what they are.
     *
     * The affixes of the part that are about a statistic (`effect` stat) multiply it again, by 1 + their amount, which a worn part gives as little of as of the rest:
     * the power goes to the engine (`power_kw`) of a part that has one and to the multiplier of the power (`power_mult`) of one that has none.
     *
     * @param  array<string, int|float>  $stats  the statistics of the definition of the part
     * @param  array<string, int>  $affixes  the affixes of the part, by key, with their amount in ppm
     * @return array<string, int|float>
     */
    public function scaledStats(array $stats, Rarity $rarity, int $upgrade = 0, int $conditionPpm = Ppm::ONE, array $affixes = []): array
    {
        // A worn part gives less (the design: x (0.6 + 0.4 x condition)), on top of what its rarity and its upgrade give.
        $condition = $this->conditionStatPpm($conditionPpm);
        $ppm = Ppm::mul($this->statPpm($rarity, $upgrade), $condition);
        $extra = $this->statAffixPpm($affixes, $condition);

        if ($ppm === Ppm::ONE && $extra === []) {
            return $stats;
        }

        $powerOn = isset($stats['power_kw']) ? 'power_kw' : 'power_mult';

        foreach ($stats as $name => $value) {
            $boost = Ppm::ONE + ($extra[$name === $powerOn ? 'power' : $name] ?? 0);

            if (in_array($name, ['grip', 'handling', 'braking', 'clearance', 'power_kw'], true)) {
                $stats[$name] = round($value * $ppm / Ppm::ONE * $boost / Ppm::ONE, 6);
            } elseif ($name === 'power_mult') {
                $stats[$name] = round((1 + ($value - 1) * $ppm / Ppm::ONE) * $boost / Ppm::ONE, 6);
            }
        }

        return $stats;
    }

    /**
     * The affixes of the rules: how many a rarity gives, and what each one is. A pack that has none (an older one) has no affixes: the parts have none, and the
     * ones they got stay without effect.
     *
     * @return array{per_rarity: array<string, array{min: int, max: int}>, definitions: array<string, array<string, mixed>>}
     */
    public function affixes(): array
    {
        return $this->data['affixes'] ?? ['per_rarity' => [], 'definitions' => []];
    }

    /**
     * What the affixes that are about a statistic add to it, in ppm, by statistic (`grip`, `handling`, `braking`, `clearance`, `power`), as much as the condition
     * of the part lets them give. An affix that the rules do not know (a pack that took it out) gives nothing.
     *
     * @param  array<string, int>  $affixes  by key, with the amount in ppm
     * @return array<string, int>
     */
    private function statAffixPpm(array $affixes, int $conditionStatPpm): array
    {
        $extra = [];

        foreach ($affixes as $key => $amount) {
            $definition = $this->affixDefinition($key);

            if ($definition !== null && $definition['effect'] === 'stat') {
                $extra[$definition['stat']] = ($extra[$definition['stat']] ?? 0) + Ppm::mul($amount, $conditionStatPpm);
            }
        }

        return $extra;
    }

    /**
     * How much the affixes of a part with this effect take off, in ppm: `wear` off how fast the part wears, `fuel` off the fuel that the car burns, `reliability`
     * off the chance of a breakdown. Affixes of one effect (a pack may have several) take their shares off one after the other.
     *
     * @param  array<string, int>  $affixes  by key, with the amount in ppm
     */
    public function affixCutPpm(array $affixes, string $effect): int
    {
        $kept = Ppm::ONE;

        foreach ($affixes as $key => $amount) {
            if (($this->affixDefinition($key)['effect'] ?? null) === $effect) {
                $kept = Ppm::mul($kept, Ppm::ONE - min(Ppm::ONE, $amount));
            }
        }

        return Ppm::ONE - $kept;
    }

    /**
     * What the affixes of the tires add to their grip on a surface, in ppm: the `surface` affixes that name it, as much as the condition of the tires lets them give.
     *
     * @param  array<string, int>  $affixes  by key, with the amount in ppm
     */
    public function surfaceAffixPpm(array $affixes, string $surface, int $conditionStatPpm = Ppm::ONE): int
    {
        $extra = 0;

        foreach ($affixes as $key => $amount) {
            $definition = $this->affixDefinition($key);

            if (($definition['effect'] ?? null) === 'surface' && in_array($surface, $definition['surfaces'], true)) {
                $extra += Ppm::mul($amount, $conditionStatPpm);
            }
        }

        return $extra;
    }

    /**
     * The effect that a legendary part of a slot has of its own (not drawn: all of them have it), or null when the slot has none.
     *
     * @return array{name: array<string, string>, special?: string, ppm?: int, mods?: list<array{effect: string, stat?: string, ppm: int}>}|null
     */
    public function uniqueEffect(string $slot): ?array
    {
        return $this->data['affixes']['unique'][$slot] ?? null;
    }

    /** The amount of the special effect of a legendary part of the slot, in ppm, when its unique effect is that one: 0 for any other part and any other effect. */
    public function uniqueSpecialPpm(string $slot, Rarity $rarity, string $special): int
    {
        $unique = $rarity === Rarity::Legendary ? $this->uniqueEffect($slot) : null;

        return $unique !== null && ($unique['special'] ?? null) === $special ? (int) $unique['ppm'] : 0;
    }

    /**
     * The affixes of a part for the pipeline: the ones it was drawn with, and, for a legendary part, the modifiers of the unique effect of its slot, which count as
     * affixes of their own (`unique:{slot}:{n}`).
     *
     * @param  array<string, int>  $affixes
     * @return array<string, int>
     */
    public function withUniqueAffixes(array $affixes, Rarity $rarity, string $slot): array
    {
        $unique = $rarity === Rarity::Legendary ? $this->uniqueEffect($slot) : null;

        foreach ($unique['mods'] ?? [] as $n => $mod) {
            $affixes["unique:{$slot}:{$n}"] = $mod['ppm'];
        }

        return $affixes;
    }

    /**
     * The condition that a part gives its statistics by: the one it has, except that the brakes with the unique effect of the legendary ones brake as new down to the
     * condition it names, and below it as worn as the condition is under that.
     */
    public function effectiveConditionPpm(string $slot, Rarity $rarity, int $conditionPpm): int
    {
        $floor = $this->uniqueSpecialPpm($slot, $rarity, 'braking_condition');

        if ($floor === 0) {
            return $conditionPpm;
        }

        return $conditionPpm >= $floor ? Ppm::ONE : intdiv(max(0, $conditionPpm) * Ppm::ONE, $floor);
    }

    /**
     * An affix by its key: one of the rules, or a modifier of a unique effect.
     *
     * @return array<string, mixed>|null
     */
    private function affixDefinition(string $key): ?array
    {
        if (str_starts_with($key, 'unique:')) {
            [, $slot, $n] = explode(':', $key);
            $mod = $this->data['affixes']['unique'][$slot]['mods'][(int) $n] ?? null;

            return $mod === null ? null : ['effect' => $mod['effect'], 'stat' => $mod['stat'] ?? null];
        }

        return $this->affixes()['definitions'][$key] ?? null;
    }

    /**
     * The crates of the shop: the odds, the guarantees and the kinds.
     *
     * @return array<string, mixed>
     */
    public function shopCrates(): array
    {
        return $this->data['shop']['crates'];
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

    /** How many jobs fit in the queue of a workshop, the ones in progress included: a place more for every mechanic at work after the first. */
    public function workshopMaxQueue(int $workers = 1): int
    {
        $workshop = $this->data['workshop'];

        return $workshop['max_queue'] + $workshop['queue_per_extra_worker'] * max(0, $workers - 1);
    }

    /**
     * How long a job of the workshop takes, in ms: the time of the slot at tier 1 times the tier, cut by the level of the
     * mechanic, then by the equipment of the workshop (the one of the slot, unless `$slotEquipment` is false, and the toolkit), and a share of that for taking a
     * part off. A workshop without a `$gear` has none. Whole numbers all the way, so the end of a job never depends on a machine.
     */
    public function workshopJobMs(string $slot, int $tier, bool $removal = false, ?WorkshopGear $gear = null, bool $slotEquipment = true): int
    {
        $workshop = $this->data['workshop'];
        $base = $workshop['base_time_ms'][$slot] ?? throw new InvalidArgumentException("The workshop has no time for the slot {$slot}.");

        $ms = $this->withGear(Ppm::mul($base * $tier, Ppm::ONE - $this->mechanic()['time_cut_ppm']), $gear, $slotEquipment ? $slot : null);

        return max(1, $removal ? Ppm::mul($ms, $workshop['removal_ppm']) : $ms);
    }

    /**
     * What a job is multiplied by in a workshop that has this equipment, in ppm: the item of the slot (the tire machine for tires, the lift for the suspension, the
     * brakes and the exhaust, the hoist for the engine, the gearbox and the boost) at its level, times the toolkit. A job that is on no slot (the service, a merge)
     * gets the toolkit only.
     */
    public function gearTimePpm(WorkshopGear $gear, ?string $slot): int
    {
        $equipment = $this->data['workshop']['equipment'];
        $ppm = $equipment[WorkshopGear::TOOLKIT]['levels'][$gear->toolkit]['time_ppm'];
        $item = $slot === null ? null : $this->equipmentItemOf($slot);

        return $item === null ? $ppm : Ppm::mul($ppm, $equipment[$item]['levels'][$gear->level($item)]['time_ppm']);
    }

    /** The time of a job in a workshop that has this equipment: `$ms` is what the rules give without any. */
    private function withGear(int $ms, ?WorkshopGear $gear, ?string $slot): int
    {
        return $gear === null ? $ms : max(1, Ppm::mul($ms, $this->gearTimePpm($gear, $slot)));
    }

    /** The item of equipment that works on the parts of a slot (`tire_machine`, `lift` or `hoist`), or null for a slot that has none (cooling, tank, ecu). */
    public function equipmentItemOf(string $slot): ?string
    {
        foreach ($this->data['workshop']['equipment'] as $item => $definition) {
            if (in_array($slot, $definition['slots'] ?? [], true)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * What a job on a part of this slot costs on top of its price in a workshop that has this equipment: the fee that the level of the item of the slot asks
     * (a workshop without a tire machine has the tires done by a vulcanizer outside). Zero for a slot that has no item, or a level that asks none.
     */
    public function gearFeeCents(WorkshopGear $gear, string $slot): int
    {
        $item = $this->equipmentItemOf($slot);

        return $item === null ? 0 : $this->data['workshop']['equipment'][$item]['levels'][$gear->level($item)]['fee_cents'] ?? 0;
    }

    /**
     * The equipment of the workshops: for every item the slots it works on and its levels (the key, the multiplier of the time in ppm, the price of getting that
     * level from the one before it, and the fee of the level).
     *
     * @return array<string, array{slots?: list<string>, levels: list<array{key: string, time_ppm: int, price_cents: int, fee_cents?: int}>}>
     */
    public function workshopEquipment(): array
    {
        return $this->data['workshop']['equipment'];
    }

    /**
     * The highest level of an item of equipment (the start state is level 0), or the most bays or mechanics a workshop has.
     */
    public function gearMax(string $item): int
    {
        return match ($item) {
            WorkshopGear::BAYS, WorkshopGear::MECHANICS => count($this->data['workshop'][$item]['price_cents']),
            default => count($this->data['workshop']['equipment'][$item]['levels']) - 1,
        };
    }

    /**
     * What the next level of an item costs when the workshop has `$level` of it (bays and mechanics are counted: a workshop with one bay buys the second),
     * or null when it has the highest.
     */
    public function gearNextPriceCents(string $item, int $level): ?int
    {
        return match ($item) {
            WorkshopGear::BAYS, WorkshopGear::MECHANICS => $this->data['workshop'][$item]['price_cents'][$level] ?? null,
            default => $this->data['workshop']['equipment'][$item]['levels'][$level + 1]['price_cents'] ?? null,
        };
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
        return $this->data['tire_matrix'][$kind][$surface] ?? throw new InvalidArgumentException("The tire matrix has no {$kind} tires on {$surface}.");
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

    /** What a track asks of the driver: `sprint`, `standard` (when the rules do not say) or `endurance`. */
    public function trackKind(string $slug): string
    {
        return (string) ($this->data['tracks'][$slug]['kind'] ?? 'standard');
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
