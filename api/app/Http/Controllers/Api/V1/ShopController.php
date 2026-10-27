<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Rules;
use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Models\Wallet;
use App\Services\CrateShop;
use App\Services\Garage;
use App\Services\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The shop of parts: what is for sale, buying a part into the inventory, and the crates with a random part of a rarity. */
final class ShopController extends Controller
{
    public function __construct(
        private readonly Shop $shop,
        private readonly CrateShop $crates,
        private readonly Garage $garage,
        private readonly Rules $rules,
    ) {}

    /** What is for sale, with what the car of the driver says about each part, and the crates with their odds and guarantees. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        return $this->respond($userId, ['data' => $this->view($userId)]);
    }

    /**
     * Opens a crate of a kind (`kind`): the part that was drawn goes to the inventory, and the answer says what it is (`opened`, with the
     * rarity). `slot` is the slot of the part for the kinds where the driver chooses it. `request_id` is chosen by the client for one intention
     * to open: sending it again charges and opens once, and answers 200 with `repeated: true`.
     */
    public function openCrate(Request $request, string $kind): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'string', 'max:'.CrateShop::MAX_REQUEST_ID_LENGTH],
            'slot' => ['nullable', 'string', 'max:32'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $opened = $this->crates->open($userId, $kind, $data['slot'] ?? null, $data['request_id']);
        $car = $this->garage->carOf($userId);

        $part = $opened === null ? null : PartResource::make($opened['part'])->resolve();
        $required = $part === null ? null : $this->rules->requiredCarLevel($part['tier']);

        return $this->respond($userId, [
            'data' => $this->view($userId),
            'repeated' => $opened === null,
            'opened' => $part === null ? null : [
                ...$part,
                'cost_cents' => $opened['opening']->price_cents,
                'required_car_level' => $required,
                'mountable' => $car !== null && $car->level >= $required,
            ],
        ], $opened === null ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function view(int $userId): array
    {
        $car = $this->garage->carOf($userId);

        return [
            'parts' => array_map(
                static fn (array $offer): array => [
                    ...PartResource::describe($offer),
                    'required_car_level' => $offer['required_car_level'],
                    'mountable' => $offer['mountable'],
                    // What the car lacks for this part besides its level: cylinders or a booster (null when nothing).
                    'blocked_by' => $offer['blocked_by'],
                ],
                $this->shop->offers($car),
            ),
            'car' => $car === null ? null : ['id' => $car->id, 'level' => $car->level],
            'crates' => $this->crates->view($userId),
        ];
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
