<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Domain\Settlement\Ppm;
use App\Exceptions\RuleRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RunResource;
use App\Http\Resources\SettlementResource;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\Wallet;
use App\Services\Garage;
use App\Services\Ledger;
use App\Services\RunService;
use App\Services\TowTerms;
use App\Services\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The run of a driver: a car driving laps on a track, settled by the server whenever it is looked at.
 *
 * Every answer has `data` (the run), `wallet` (cash in cents and XP after the call) and is never cached,
 * because it is true only at the moment of the call.
 */
final class RunController extends Controller
{
    /** The most settlements one answer carries. A longer absence is read in several steps, from the last version. */
    private const MAX_SETTLEMENTS = 100;

    public function __construct(
        private readonly RunService $runs,
        private readonly Catalog $catalog,
        private readonly Rules $rules,
        private readonly RunInputsFactory $inputs,
        private readonly Workshop $workshop,
        private readonly Ledger $ledger,
        private readonly Garage $garage,
    ) {}

    /** Starts the run of the driver: one of the starter wrecks on a track, with a full tank. The car waits in the garage until `start`. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'car' => ['required', 'string', Rule::in(array_keys($this->catalog->startingVehicles()))],
            'track' => ['required', 'string', Rule::in(array_keys($this->rules->tracks()))],
        ]);

        $this->assertTrackOpen((int) $request->user()->getAuthIdentifier(), $data['track']);

        // The wreck has driven what the board of the wrecks says, and the run is frozen with that odometer, so that it wears and is serviced from its first lap.
        $odometerM = $this->rules->wreckMileageKm($data['car']) * 1000;

        $run = $this->runs->startFirstRun(
            (int) $request->user()->getAuthIdentifier(),
            $data['car'],
            $data['track'],
            $this->inputs->make($data['car'], $data['track'], [], Ppm::ONE, ['mileage_m' => $odometerM, 'serviced_at_m' => $odometerM]),
            $this->inputs->tankMl($data['car']),
            parked: true,
        );

        return $this->respond($run, 201);
    }

    /**
     * Sends out the car that waits in the garage (the Start of the tournament screen): it drives from now on. A car that does not wait is left
     * as it is, so a repeated request is harmless.
     */
    public function start(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        if ($found === null) {
            return $this->noRun();
        }

        return $this->respond($this->runs->begin($found->id));
    }

    /**
     * Buys fuel. `request_id` is chosen by the client for one intention to refuel: sending it again, for example
     * after an answer was lost, charges and fills once. `liters` is how much to buy, or the whole tank when
     * left out, and never more than the tank holds. What the car earned until now is settled first and can pay for it.
     */
    public function refuel(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        if ($found === null) {
            return $this->noRun();
        }

        $data = $request->validate([
            'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH],
            'liters' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $refueled = $this->runs->refuel(
            $found->id,
            $data['request_id'],
            $this->inputs->tankMl($found->car_slug, $this->mountedOn($found)),
            $this->rules->fuelPriceCentsPerLiter(),
            isset($data['liters']) ? $data['liters'] * 1000 : null,
        );

        return $this->respond($refueled->run, 200, [
            'refuel' => ['added_ml' => $refueled->addedMl, 'cost_cents' => $refueled->costCents, 'repeated' => $refueled->repeated],
        ]);
    }

    /**
     * Changes the settings of the run, which for now is the track. Everything until now is settled with the old
     * settings and pays out. The car then drives the new track from this moment: the lap in progress is dropped,
     * and the planned breakdown does not move, because the seed of the run stays. A car that stopped stays stopped.
     */
    public function update(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        if ($found === null) {
            return $this->noRun();
        }

        $data = $request->validate([
            'track' => ['required', 'string', Rule::in(array_keys($this->rules->tracks()))],
        ]);

        $this->assertTrackOpen((int) $request->user()->getAuthIdentifier(), $data['track']);

        // Another city is reached by a journey, which takes time and fuel, not by a change of the track.
        if ($this->rules->track($data['track'])['region'] !== $this->rules->track($found->track_slug)['region']) {
            throw new RuleRefusedException('track_elsewhere');
        }

        $changed = $this->runs->reconfigure($found->id, $this->inputs->forRun($found, $data['track']), trackSlug: $data['track']);

        return $this->respond($changed);
    }

    /**
     * Repairs a car that broke down for the cost of the rules and sends it on its way from now, with a new plan
     * of the next breakdown. What the car earned before it broke down is settled first and can pay for the repair.
     * A car that did not break down is left as it is and `repaired` says false, so a repair cannot be used to
     * draw the breakdown again. `request_id` makes a repeated request harmless, as in refuel.
     */
    public function repair(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        if ($found === null) {
            return $this->noRun();
        }

        $data = $request->validate([
            'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH],
        ]);

        $repaired = $this->runs->repair(
            $found->id,
            $this->inputs->forRun($found),
            costCents: $this->rules->repairCostCents(),
            requestId: $data['request_id'],
        );

        return $this->respond($repaired->run, 200, [
            'repair' => ['repaired' => $repaired->repaired, 'cost_cents' => $repaired->costCents, 'repeated' => $repaired->repeated],
        ]);
    }

    /**
     * Tows a stopped car for a driver who cannot pay to get it going: the repair after a breakdown, the fuel for a lap
     * when the tank is too empty. It is free, but the car waits `tow.delay_ms` before it drives again, and a car with a
     * tank too empty for a lap leaves with a little fuel. `towed` says whether it was done: a car that is driving, or
     * whose driver can pay after all, is left as it is, so asking twice tows once.
     */
    public function tow(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        if ($found === null) {
            return $this->noRun();
        }

        $towed = $this->runs->tow($found->id, $this->inputs->forRun($found), TowTerms::fromRules($this->rules));

        return $this->respond($towed->run, 200, ['towed' => $towed->towed]);
    }

    /** The run of the driver, settled up to now. */
    public function current(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);

        // The run of the car the driver plays.
        $run = $this->garage->selectedRun($userId);

        return $run === null ? $this->noRun() : $this->summary($request, $run);
    }

    /** A run of the driver by id, settled up to now. */
    public function show(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        return $found === null ? $this->noRun() : $this->summary($request, $found);
    }

    /**
     * A run by id, but only if it is the driver's own: another driver's run is as good as not there. What is due in the
     * workshop is carried out first, so that nothing settles the run past the moment a job started.
     */
    private function ownRun(Request $request, int $id): ?RaceRun
    {
        $this->workshop->advance((int) $request->user()->getAuthIdentifier());

        return RaceRun::query()->where('user_id', $request->user()->getAuthIdentifier())->find($id);
    }

    /**
     * The parts on the car of the run, by slot, with their rarity.
     *
     * @return array<string, array{slug: string, rarity: int}>
     */
    private function mountedOn(RaceRun $run): array
    {
        return $run->car?->mountedParts() ?? [];
    }

    /** The condition of the car of a run: a worn one breaks down more. */
    private function conditionOf(RaceRun $run): int
    {
        return $run->car?->conditionPpm() ?? Ppm::ONE;
    }

    /**
     * Settles the run up to now and tells what happened. `settlements` are the ones this call made, or, with
     * `since_version` (the `state_version` the client saw last), all made since, so nothing is lost when an answer
     * does not arrive. `summary` adds them up.
     */
    private function summary(Request $request, RaceRun $run): JsonResponse
    {
        $request->validate(['since_version' => ['nullable', 'integer', 'min:0']]);

        $settled = $this->runs->settle($run->id);

        $settlements = $request->filled('since_version')
            ? Settlement::query()
                ->where('race_run_id', $run->id)
                ->where('state_version', '>=', $request->integer('since_version'))
                ->orderBy('state_version')
                ->limit(self::MAX_SETTLEMENTS)
                ->with('events')
                ->get()
            : collect($settled->all());

        return $this->respond($run->refresh(), 200, [
            'settlements' => SettlementResource::collection($settlements)->resolve(),
            'summary' => [
                'laps' => (int) $settlements->sum('laps'),
                'cash_cents' => (int) $settlements->sum('cash_cents'),
                'xp' => (int) $settlements->sum('xp'),
                'from_ms' => $settlements->min('cursor_from_ms'),
                'to_ms' => $settlements->max('cursor_to_ms'),
                'stop_reason' => $settlements->pluck('stop_reason')->filter()->last(),
            ],
        ]);
    }

    /**
     * A track is open to a driver whose level reaches it. The refusal says what level it takes and which one the driver is, so
     * that a locked track always shows the reason and the way to it: the level comes from the XP that laps pay.
     */
    private function assertTrackOpen(int $userId, string $track): void
    {
        $required = $this->rules->trackMinPlayerLevel($track);
        $current = $this->rules->playerLevelAt($this->ledger->xp($userId));

        if ($current < $required) {
            throw new RuleRefusedException('track_locked', ['required' => $required, 'current' => $current]);
        }
    }

    private function noRun(): JsonResponse
    {
        return response()->json(['message' => __('game.errors.no_run'), 'code' => 'no_run'], 404)->header('Cache-Control', 'no-store');
    }

    /** @param  array<string, mixed>  $extra  more top level keys of the answer */
    private function respond(RaceRun $run, int $status = 200, array $extra = []): JsonResponse
    {
        $wallet = Wallet::query()->find($run->user_id);
        $cash = $wallet?->cash_cents ?? 0;
        $terms = TowTerms::fromRules($this->rules);

        return (new RunResource($run))
            ->additional([
                'wallet' => ['cash_cents' => $cash, 'xp' => $wallet?->xp ?? 0],
                // Whether the driver may ask for a tow now, decided by the rule the tow itself uses.
                'tow' => ['available' => $terms->isDue($run, $cash), 'delay_ms' => $terms->delayMs],
                ...$extra,
            ])
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
