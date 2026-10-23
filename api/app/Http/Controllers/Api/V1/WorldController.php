<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RunService;
use App\Services\Travel;
use App\Services\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The map of the world: the cities with what they ask and cost, the roads between them, and the journey of the car. Travelling
 * is a timed job of the car, carried out lazily like the ones of the workshop.
 */
final class WorldController extends Controller
{
    public function __construct(
        private readonly Travel $travel,
        private readonly Workshop $workshop,
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
        $data = $request->validate(['to' => ['required', 'string', 'max:64'], 'car' => ['nullable', 'integer', 'min:1']]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $this->travel->start($userId, $data['to'], $data['car'] ?? null);

        return $this->respond($userId, 201);
    }

    private function respond(int $userId, int $status = 200): JsonResponse
    {
        $nowMs = RunService::nowMs();

        return response()->json(['data' => $this->travel->map($userId, $nowMs), 'now_ms' => $nowMs], $status)->header('Cache-Control', 'no-store');
    }
}
