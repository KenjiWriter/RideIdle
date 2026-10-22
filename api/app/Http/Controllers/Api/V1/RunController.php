<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Catalog;
use App\Content\Rules;
use App\Content\RunInputsFactory;
use App\Http\Controllers\Controller;
use App\Http\Resources\RunResource;
use App\Models\RaceRun;
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

    private function respond(RaceRun $run, int $status = 200): JsonResponse
    {
        $wallet = Wallet::query()->find($run->user_id);

        return (new RunResource($run))
            ->additional(['wallet' => ['cash_cents' => $wallet?->cash_cents ?? 0, 'xp' => $wallet?->xp ?? 0]])
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
