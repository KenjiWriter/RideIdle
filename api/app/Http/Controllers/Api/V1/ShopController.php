<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Models\Wallet;
use App\Services\Garage;
use App\Services\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The shop of parts: what is for sale, and buying a part into the inventory. */
final class ShopController extends Controller
{
    public function __construct(private readonly Shop $shop, private readonly Garage $garage) {}

    /** What is for sale, with what the car of the driver says about each part. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $car = $this->garage->carOf($userId);

        return $this->respond($userId, [
            'data' => [
                'parts' => array_map(
                    static fn (array $offer): array => [
                        ...PartResource::describe($offer),
                        'required_car_level' => $offer['required_car_level'],
                        'mountable' => $offer['mountable'],
                    ],
                    $this->shop->offers($car),
                ),
                'car' => $car === null ? null : ['id' => $car->id, 'level' => $car->level],
            ],
        ]);
    }

    /**
     * Buys a part. `request_id` is chosen by the client for one intention to buy: sending it again, for example after an
     * answer was lost, charges and gives once and answers 200 with `purchase.repeated`.
     */
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'part' => ['required', 'string', 'max:64'],
            'request_id' => ['required', 'string', 'max:'.Shop::MAX_REQUEST_ID_LENGTH],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $purchased = $this->shop->buy($userId, $data['part'], $data['request_id']);

        return $this->respond($userId, [
            'data' => $purchased->part === null ? null : PartResource::make($purchased->part)->resolve(),
            'purchase' => ['cost_cents' => $purchased->costCents, 'repeated' => $purchased->repeated],
        ], $purchased->repeated ? 200 : 201);
    }

    /** @param  array<string, mixed>  $body */
    private function respond(int $userId, array $body, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$body,
            'wallet' => ['cash_cents' => Wallet::query()->find($userId)?->cash_cents ?? 0],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
