<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Http\Controllers\Controller;
use App\Http\Resources\RunResource;
use App\Http\Resources\SettlementResource;
use App\Models\RaceRun;
use App\Models\Settlement;
use App\Models\Wallet;
use App\Services\RunService;
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
    ) {
    }

    /** Starts the run of the driver: one of the starter wrecks on a track, with a full tank. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'car' => ['required', 'string', Rule::in(array_keys($this->catalog->startingVehicles()))],
            'track' => ['required', 'string', Rule::in(array_keys($this->rules->tracks()))],
        ]);

        $run = $this->runs->startFirstRun(
            (int) $request->user()->getAuthIdentifier(),
            $data['car'],
            $data['track'],
            $this->inputs->make($data['car'], $data['track']),
            $this->inputs->tankMl($data['car']),
        );

        return $this->respond($run, 201);
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
            $this->inputs->tankMl($found->car_slug),
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

        $changed = $this->runs->reconfigure($found->id, $this->inputs->make($found->car_slug, $data['track']), trackSlug: $data['track']);

        return $this->respond($changed);
    }

    /** The run of the driver, settled up to now. */
    public function current(Request $request): JsonResponse
    {
        $run = RaceRun::query()->where('user_id', $request->user()->getAuthIdentifier())->orderByDesc('id')->first();

        return $run === null ? $this->noRun() : $this->summary($request, $run);
    }

    /** A run of the driver by id, settled up to now. */
    public function show(Request $request, int $run): JsonResponse
    {
        $found = $this->ownRun($request, $run);

        return $found === null ? $this->noRun() : $this->summary($request, $found);
    }

    /** A run by id, but only if it is the driver's own: another driver's run is as good as not there. */
    private function ownRun(Request $request, int $id): ?RaceRun
    {
        return RaceRun::query()->where('user_id', $request->user()->getAuthIdentifier())->find($id);
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
            : collect([$settled->settlement])->filter()->values();

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

    private function noRun(): JsonResponse
    {
        return response()->json(['message' => __('game.errors.no_run'), 'code' => 'no_run'], 404)->header('Cache-Control', 'no-store');
    }

    /** @param  array<string, mixed>  $extra  more top level keys of the answer */
    private function respond(RaceRun $run, int $status = 200, array $extra = []): JsonResponse
    {
        $wallet = Wallet::query()->find($run->user_id);

        return (new RunResource($run))
            ->additional(['wallet' => ['cash_cents' => $wallet?->cash_cents ?? 0, 'xp' => $wallet?->xp ?? 0], ...$extra])
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
