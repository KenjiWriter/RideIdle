<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use App\Services\Market;
use App\Services\RunService;
use App\Services\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The market of the city where the car the driver plays stands: the used-car exchange (inspect a car, buy it), the dealer of new cars and
 * the wrecks of the yard (bought with `POST /cars`, which is the fleet's). A car that is bought stands in the garage of that city.
 */
final class MarketController extends Controller
{
    public function __construct(
        private readonly Market $market,
        private readonly Workshop $workshop,
        private readonly Ledger $ledger,
    ) {}

    /** The market as it is now: an arrival that is due is carried out first, so the city is the one the car is in. */
    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);

        return $this->respond($userId);
    }

    /** Has a car of the exchange inspected: from then on its condition is shown. Paid once, however many times it is asked. */
    public function inspect(Request $request, string $key): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $this->market->inspect($userId, $key, RunService::nowMs());

        return $this->respond($userId);
    }

    /** Buys a car of the exchange: it is bought once, and a repeated request answers 200 with `repeated: true`. */
    public function buyListing(Request $request, string $key): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $car = $this->market->buyListing($userId, $key, RunService::nowMs());

        return $this->respond($userId, $car === null ? 200 : 201, ['repeated' => $car === null, 'bought' => $car?->id]);
    }

    /** Buys a new car from the dealer: `vehicle` is the slug, `request_id` makes a repeated request harmless (it buys nothing twice). */
    public function buyNew(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle' => ['required', 'string', 'max:64'],
            'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->workshop->advance($userId);
        $car = $this->market->buyNew($userId, $data['vehicle'], $data['request_id'], RunService::nowMs());

        return $this->respond($userId, $car === null ? 200 : 201, ['repeated' => $car === null, 'bought' => $car?->id]);
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, int $status = 200, array $extra = []): JsonResponse
    {
        $nowMs = RunService::nowMs();

        return response()->json([
            'data' => $this->market->view($userId, $nowMs),
            'wallet' => ['cash_cents' => $this->ledger->balance($userId), 'xp' => $this->ledger->xp($userId)],
            'now_ms' => $nowMs,
            ...$extra,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
