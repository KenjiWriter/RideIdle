<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use App\Services\RunService;
use App\Services\Travel;
use App\Services\TravelPolice;
use App\Services\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The map of the world: the cities with what they ask and cost, the roads between them, and the journey of the car. Travelling
 * is a timed job of the car, carried out lazily like the ones of the workshop.
 */
final class WorldController extends Controller
{
    public function __construct(
        private readonly Travel $travel,
        private readonly Workshop $workshop,
        private readonly Ledger $ledger,
    ) {}

    /** The map as it is now: an arrival that is due is carried out first. */
    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);

        return $this->respond($userId);
    }

    /**
     * Sends a car to another city: `to` is the slug of a city, `car` the id of the car (the one the driver plays when left out). The car earns until it leaves, then stands on the road for as long as the
     * journey takes, with the fuel of the road out of its tank. The level of the driver must open the city.
     */
    public function travel(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:64'], 'car' => ['nullable', 'integer', 'min:1'], 'boost' => ['nullable', 'boolean']]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $this->travel->start($userId, $data['to'], $data['car'] ?? null, boost: (bool) ($data['boost'] ?? false));

        return $this->respond($userId, 201);
    }

    /**
     * The choice of the driver when the police have stopped the car of a boosted journey: `choice` is `pay` (the fine, and the car stands a little), `bribe` (more, no stop
     * when it works, and it may not) or `lawyer` (premium coins, no fine and no stop). `car` is the id of the car (the one the driver plays when left out). The same choice
     * sent again changes nothing (`repeated`). Answers with the map and `police`: what came of it.
     */
    public function police(Request $request): JsonResponse
    {
        $data = $request->validate(['choice' => ['required', 'string', Rule::in(TravelPolice::CHOICES)], 'car' => ['nullable', 'integer', 'min:1']]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $done = $this->travel->decide($userId, $data['car'] ?? null, $data['choice']);

        return $this->respond($userId, 200, ['repeated' => $done['repeated'], 'police' => ['choice' => $done['journey']->police_choice, 'outcome' => $done['journey']->police_outcome, 'paid_cents' => $done['journey']->police_paid_cents]]);
    }

    /**
     * Brings a car to the city it is going to, now, for premium coins (`travel.speedup.coins` of the map says how many). `car` is the id of the car (the one the
     * driver plays when left out). `request_id` is one intention: sending it again charges nothing and answers with `repeated: true`.
     */
    public function finishTravel(Request $request): JsonResponse
    {
        $data = $request->validate(['car' => ['nullable', 'integer', 'min:1'], 'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH]]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $done = $this->travel->finish($userId, $data['car'] ?? null, $data['request_id']);

        return $this->respond($userId, 200, ['repeated' => $done === null, 'spent_coins' => $done['coins'] ?? 0]);
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, int $status = 200, array $extra = []): JsonResponse
    {
        $nowMs = RunService::nowMs();

        return response()->json(['data' => $this->travel->map($userId, $nowMs), 'now_ms' => $nowMs, 'premium_coins' => $this->ledger->premiumBalance($userId), ...$extra], $status)->header('Cache-Control', 'no-store');
    }
}
