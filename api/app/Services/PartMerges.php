<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Catalog;
use App\Content\Rules;
use App\Domain\Run\Rarity;
use App\Domain\Run\Rng;
use App\Domain\Workshop\WorkshopGear;
use App\Http\Resources\PartResource;
use App\Models\MergeAttempt;
use App\Models\Part;
use App\Models\WorkshopJob;
use App\Support\I18nText;
use Illuminate\Support\Collection;

/**
 * Merging two parts (Systemy I, point 3): two parts of one slot, tier and rarity that are not on a car make one part of the next rarity, or fail
 * and are lost, and the driver gets a part of the price of the model back as scrap. The attempt is a job of the workshop that waits for the
 * mechanic; the draw is made when he is done, on the server, from a seed that the driver cannot see and that is written down with the result.
 *
 * The chance of the attempt that makes the rarity `r` is the base of `r`, a percentage point for every level of the mechanic, and three for every
 * failure since the last success at that slot and rarity (the protection from bad luck, counted from the attempts that were made).
 */
final class PartMerges
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Rules $rules,
        private readonly Catalog $catalog,
        private readonly PartAffixes $affixes,
        private readonly TutorialScript $script,
    ) {}

    /**
     * Carries out the attempt of a job that is done: draws, and makes the new part or loses both. The locks of the wallet and the runs are held.
     * The base part (the first one, whose model stays) becomes the part of the next rarity with its upgrade; the other one is used up.
     */
    public function resolve(WorkshopJob $job): MergeAttempt
    {
        $base = Part::query()->lockForUpdate()->findOrFail($job->part_id);
        $other = Part::query()->lockForUpdate()->findOrFail($job->merge_part_id);
        $to = Rarity::from($base->rarity + 1);

        // The first merge of the tutorial cannot fail: the job was queued under the arrangement of the step.
        $chance = $job->scripted ? Ppm::ONE : $this->rules->mergeChancePpm($to, $this->failuresSince($job->user_id, $base->slot, $to));
        $seed = hash_hmac('sha256', "merge|{$job->user_id}|".MergeAttempt::query()->where('user_id', $job->user_id)->count(), (string) config('app.key'));
        $roll = Rng::roll($seed, 'merge', 0);
        $success = $roll < $chance;
        $price = (int) ($this->catalog->part($base->part_slug)['price_cents'] ?? 0);
        $scrap = $success ? 0 : $this->rules->mergeScrapCents($price);
        $slug = $base->part_slug;
        $slot = $base->slot;
        $tier = (int) ($this->catalog->part($slug)['tier'] ?? 0);

        if ($success) {
            $base->forceFill(['rarity' => $to->value])->save();
            $this->affixes->give($base, $to);
            $other->delete();
        } else {
            $base->delete();
            $other->delete();

            if ($scrap > 0) {
                $this->ledger->post($job->user_id, $scrap, 'merge_scrap', "merge-scrap:{$job->id}");
            }
        }

        return MergeAttempt::query()->create([
            'user_id' => $job->user_id,
            'job_id' => $job->id,
            'slot' => $slot,
            'tier' => $tier,
            'to_rarity' => $to->value,
            'part_slug' => $slug,
            'part_id' => $success ? $base->id : null,
            'chance_ppm' => $chance,
            'roll_ppm' => $roll,
            'success' => $success,
            'scrap_cents' => $scrap,
            'seed' => $seed,
            'finished_ms' => $job->finish_ms,
        ]);
    }

    /** How many attempts at this slot and rarity failed since the last one that succeeded: what the protection from bad luck counts. */
    public function failuresSince(int $userId, string $slot, Rarity $to): int
    {
        $attempts = MergeAttempt::query()->where('user_id', $userId)->where('slot', $slot)->where('to_rarity', $to->value);
        $lastSuccess = (clone $attempts)->where('success', true)->max('id');

        return (clone $attempts)->when($lastSuccess !== null, fn ($query) => $query->where('id', '>', $lastSuccess))->count();
    }

    /**
     * What a part of the inventory can be merged into, by the id of the part: null for a part that cannot be (a legendary one, one that waits in a job,
     * or a model with no price), otherwise the rarity it aims at, the chance with the protection counted in, what the attempt costs and takes, the scrap a
     * failure gives, and the other parts it can be merged with (the same slot, tier and rarity, not waiting in a job).
     *
     * @param  Collection<int, Part>  $inventory  the parts of the driver that are on no car
     * @param  list<int>  $busy  the ids of the parts that wait in a job
     * @param  WorkshopGear|null  $gear  the equipment of the workshop that would do it, which the time depends on (the toolkit)
     * @return array<int, array{to: string, chance_ppm: int, pity_ppm: int, cost_cents: int, duration_ms: int, scrap_cents: int, with: list<int>}|null>
     */
    public function quotes(int $userId, Collection $inventory, array $busy, ?WorkshopGear $gear = null): array
    {
        $groups = $inventory->reject(fn (Part $part): bool => in_array($part->id, $busy, true))
            ->groupBy(fn (Part $part): string => $part->slot.'|'.($this->catalog->part($part->part_slug)['tier'] ?? 0).'|'.$part->rarity);

        $quotes = [];
        $arranged = $this->script->arrangements($userId)[WorkshopJob::KIND_MERGE] ?? null;

        foreach ($inventory as $part) {
            $quotes[$part->id] = null;
        }

        foreach ($groups as $parts) {
            /** @var Part $first */
            $first = $parts->first();
            $rarity = Rarity::tryFrom((int) $first->rarity);

            if ($rarity === null || $rarity === Rarity::Legendary) {
                continue;
            }

            $to = Rarity::from($rarity->value + 1);
            $failures = $this->failuresSince($userId, $first->slot, $to);
            // The step of the tutorial that arranges a merge makes it sure.
            $chance = $arranged === null ? $this->rules->mergeChancePpm($to, $failures) : Ppm::ONE;
            $withoutPity = $arranged === null ? $this->rules->mergeChancePpm($to, 0) : Ppm::ONE;

            foreach ($parts as $part) {
                $definition = $this->catalog->part($part->part_slug);

                if (($definition['price_cents'] ?? null) === null) {
                    continue;
                }

                $quotes[$part->id] = [
                    'to' => $to->key(),
                    'chance_ppm' => $chance,
                    'pity_ppm' => $chance - $withoutPity,
                    ...$this->script->arrange(['cost_cents' => $this->rules->mergeCostCents($definition['price_cents']), 'duration_ms' => $this->rules->mergeJobMs($part->slot, $definition['tier'], $gear)], $arranged),
                    'scrap_cents' => $this->rules->mergeScrapCents($definition['price_cents']),
                    'with' => $parts->where('id', '!=', $part->id)->pluck('id')->values()->all(),
                ];
            }
        }

        return $quotes;
    }

    /**
     * The last attempts of the driver, the newest first, as the client shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $userId, int $limit = 5): array
    {
        $attempts = MergeAttempt::query()->where('user_id', $userId)->orderByDesc('id')->limit($limit)->get();
        $parts = Part::query()->whereIn('id', $attempts->pluck('part_id')->filter())->get()->keyBy('id');

        return $attempts->map(function (MergeAttempt $attempt) use ($parts): array {
            $definition = $this->catalog->part($attempt->part_slug);
            $part = $attempt->part_id === null ? null : $parts->get($attempt->part_id);

            return [
                'id' => $attempt->id,
                'success' => $attempt->success,
                'slot' => $attempt->slot,
                'tier' => $attempt->tier,
                'to' => Rarity::from($attempt->to_rarity)->key(),
                'name' => isset($definition['name']) ? I18nText::from($definition['name'])->for() : $attempt->part_slug,
                'chance_ppm' => $attempt->chance_ppm,
                'scrap_cents' => $attempt->scrap_cents,
                'part' => $part === null ? null : PartResource::make($part)->resolve(),
                'finished_ms' => $attempt->finished_ms,
            ];
        })->values()->all();
    }
}
