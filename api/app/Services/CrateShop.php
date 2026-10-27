<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Market\Crates;
use App\Domain\Run\Rarity;
use App\Domain\Settlement\Ppm;
use App\Exceptions\RuleRefusedException;
use App\Models\CrateOpening;
use App\Models\LedgerEntry;
use App\Models\Part;
use App\Support\I18nText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The crates of the shop (Systemy III, point 11): one draw of a part of a rarity, for game cash. The odds and the guarantees are shown before
 * the purchase, the draw is made by the server (from a seed that is made of the secret of the application, the driver, the group of crates and
 * the number of the opening, so that nobody can know the part before or fish for it), and every opening is written down.
 *
 * What a crate can give is the parts of the shop in a slot, of the highest tier that the level of the driver opens: a driver of a higher level
 * gets better parts and pays more, and a model of that tier is drawn with equal chances. The part is new, goes to the inventory, and the rarity
 * is the one that was drawn; putting it on a car is a job of the workshop, as with every part.
 */
final class CrateShop
{
    /** The key of the posting is crate:{driver}:{request}: 6 + 20 + 1 + the request id, within the 128 of the ledger. */
    public const MAX_REQUEST_ID_LENGTH = 64;

    private readonly Crates $crates;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly Ledger $ledger,
        private readonly TutorialScript $script,
    ) {
        $this->crates = new Crates($rules->shopCrates());
    }

    /**
     * The crates for the screen: for each group the odds and how far the driver is from the guarantees, and for each kind what it costs, the
     * slots it can give, and the models of each.
     *
     * @return array{level: int, free_first: bool, groups: array<string, array<string, mixed>>, kinds: list<array<string, mixed>>}
     */
    public function view(int $userId): array
    {
        $level = $this->levelOf($userId);
        $pools = $this->openPools($level);
        // The first crate that a driver opens in the tutorial costs nothing, and the screen says so with the price.
        $free = $this->script->freeFirstCrate($userId);
        $groups = [];

        foreach (array_keys($this->rules->shopCrates()['odds']) as $group) {
            $since = $this->since($userId, $group);

            $groups[$group] = [
                'odds' => $this->crates->oddsPercent($group),
                'every' => $this->crates->floorEvery($group),
                'min_rarity' => $this->crates->floorRarity($group)->key(),
                'until' => max(1, $this->crates->floorEvery($group) - $since['floor']),
                'legendary_every' => $this->crates->legendaryEvery($group),
                'legendary_until' => max(1, $this->crates->legendaryEvery($group) - $since['legendary']),
            ];
        }

        $kinds = [];

        foreach ($this->crates->kinds() as $key => $kind) {
            $open = $level >= $kind['min_player_level'] && $pools !== [];

            $kinds[] = [
                'key' => $key,
                'group' => $kind['group'],
                'min_player_level' => $kind['min_player_level'],
                'open' => $open,
                'choose_slot' => $kind['choose_slot'],
                // A crate of a slot costs by the slot, and a mixed one has one price, whatever slot it gives.
                'price_cents' => $open && ! $kind['choose_slot'] ? ($free ? 0 : $this->priceCents($kind, $pools)) : null,
                'slots' => $open ? array_values(array_map(fn (string $slot, array $pool): array => [
                    'slot' => $slot,
                    'tier' => $pool['tier'],
                    'price_cents' => $kind['choose_slot'] ? ($free ? 0 : $this->priceCents($kind, [$pool])) : null,
                    'models' => array_map(static fn (array $part): array => ['slug' => $part['slug'], 'name' => I18nText::from($part['name'])->for()], $pool['parts']),
                ], array_keys($pools), $pools)) : [],
            ];
        }

        return ['level' => $level, 'free_first' => $free, 'groups' => $groups, 'kinds' => $kinds];
    }

    /**
     * Opens a crate: the price is paid, the part is drawn and put in the inventory, and the opening is written down, all in one transaction under
     * the lock of the wallet. `$requestId` makes a repeated request harmless: a crate that was paid already opens nothing, and the answer is null.
     *
     * @param  string|null  $slot  the slot of the part, for the kinds where the driver chooses it
     * @return array{part: Part, opening: CrateOpening}|null
     *
     * @throws RuleRefusedException `crate_not_found`, `crate_level_required`, `crate_slot_unavailable`
     */
    public function open(int $userId, string $kind, ?string $slot, string $requestId): ?array
    {
        $this->ledger->ensureWallet($userId);

        return DB::transaction(function () use ($userId, $kind, $slot, $requestId): ?array {
            $this->ledger->lockWallet($userId);

            $key = "crate:{$userId}:{$requestId}";

            // A crate that cost nothing left no posting, so the request that opened it is what says it was opened.
            if (LedgerEntry::query()->where('idem_key', $key)->exists() || CrateOpening::query()->where('user_id', $userId)->where('request_id', $requestId)->exists()) {
                return null;
            }

            $definition = $this->crates->kinds()[$kind] ?? throw new RuleRefusedException('crate_not_found');
            $level = $this->levelOf($userId);

            if ($level < $definition['min_player_level']) {
                throw new RuleRefusedException('crate_level_required', ['required' => $definition['min_player_level'], 'current' => $level]);
            }

            $pools = $this->openPools($level);
            $group = $definition['group'];
            $index = CrateOpening::query()->where('user_id', $userId)->where('pity_group', $group)->count();
            $seed = hash_hmac('sha256', "crate|{$userId}|{$group}|{$index}", (string) config('app.key'));

            if ($definition['choose_slot']) {
                $pool = $pools[$slot ?? ''] ?? throw new RuleRefusedException('crate_slot_unavailable');
                $chosen = $slot;
                $price = $this->priceCents($definition, [$pool]);
            } else {
                if ($pools === []) {
                    throw new RuleRefusedException('crate_slot_unavailable');
                }

                $slots = array_keys($pools);
                $chosen = $slots[$this->crates->pick($seed, 'slot', count($slots))];
                $pool = $pools[$chosen];
                $price = $this->priceCents($definition, $pools);
            }

            // The first crate of the tutorial is free: no posting, and the request is kept on the opening.
            $free = $this->script->freeFirstCrate($userId);
            $price = $free ? 0 : $price;

            if ($price > 0) {
                $this->ledger->post($userId, -$price, 'crate', $key);
            }

            $since = $this->since($userId, $group);
            $rarity = $this->crates->draw($seed, $group, $since['floor'], $since['legendary']);
            $model = $pool['parts'][$this->crates->pick($seed, 'model', count($pool['parts']))];

            $part = Part::query()->create([
                'user_id' => $userId,
                'part_slug' => $model['slug'],
                'slot' => $model['slot'],
                'rarity' => $rarity->value,
            ]);

            $opening = CrateOpening::query()->create([
                'user_id' => $userId,
                'kind' => $kind,
                'pity_group' => $group,
                'opening_index' => $index,
                'slot' => $chosen,
                'tier' => $pool['tier'],
                'rarity' => $rarity->value,
                'part_slug' => $model['slug'],
                'price_cents' => $price,
                'request_id' => $free ? $requestId : null,
                'part_id' => $part->id,
            ]);

            return ['part' => $part, 'opening' => $opening];
        }, Ledger::DEADLOCK_ATTEMPTS);
    }

    /**
     * What a crate can give at a level of the driver, by slot (in the order of the shop): the parts of the shop of the highest tier of the slot
     * that the level opens.
     *
     * @return array<string, array{tier: int, parts: list<array<string, mixed>>}>
     */
    private function openPools(int $level): array
    {
        $byTier = [];

        foreach ($this->rules->shopParts() as $slug) {
            $part = $this->catalog->part($slug);

            if ($part === null || $this->crates->playerLevelOfTier($part['tier']) > $level) {
                continue;
            }

            $byTier[$part['slot']][$part['tier']][] = $part;
        }

        $pools = [];

        foreach ($byTier as $slot => $tiers) {
            krsort($tiers);
            $tier = array_key_first($tiers);
            $pools[$slot] = ['tier' => $tier, 'parts' => $tiers[$tier]];
        }

        return $pools;
    }

    /**
     * What a crate costs: a share of the mean price of the models it can give, in whole dollars, with the factor of the tier of each slot.
     *
     * @param  array{price_ppm: int}  $kind
     * @param  array<string, array{tier: int, parts: list<array<string, mixed>>}>  $pools  the slots the crate can give
     */
    private function priceCents(array $kind, array $pools): int
    {
        $means = array_map(fn (array $pool): float => array_sum(array_column($pool['parts'], 'price_cents')) / count($pool['parts']) * $this->crates->tierPriceFactorPpm($pool['tier']) / Ppm::ONE, $pools);
        $cents = array_sum($means) / count($means) * $kind['price_ppm'] / Ppm::ONE;

        return max(100, (int) (round($cents / 100) * 100));
    }

    /**
     * How many crates of the group the driver opened since the last part of the rarity of the guarantee or better, and since the last legendary one.
     *
     * @return array{floor: int, legendary: int}
     */
    private function since(int $userId, string $group): array
    {
        $openings = CrateOpening::query()->where('user_id', $userId)->where('pity_group', $group);
        $total = (clone $openings)->count();

        return [
            'floor' => $this->sinceRarity($openings, $this->crates->floorRarity($group)->value, $total),
            'legendary' => $this->sinceRarity($openings, Rarity::Legendary->value, $total),
        ];
    }

    /** @param  Builder<CrateOpening>  $openings */
    private function sinceRarity(Builder $openings, int $rarity, int $total): int
    {
        $last = (clone $openings)->where('rarity', '>=', $rarity)->max('opening_index');

        return $last === null ? $total : (clone $openings)->where('opening_index', '>', $last)->count();
    }

    private function levelOf(int $userId): int
    {
        return $this->rules->playerLevelAt($this->ledger->xp($userId));
    }
}
