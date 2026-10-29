<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Entitlements;
use App\Services\Premium;
use App\Services\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The premium currency of the driver and what it buys: the balance, the packs that are sold for real money, the last purchases, and the three paid extras (the
 * auto-refill, the VIP account and the pass of the endless refill) with what the driver has of them. Coins buy the charges of the auto-refill and the VIP; the pass
 * is sold for money and cannot be bought in the game until a payment provider is connected.
 */
final class PremiumController extends Controller
{
    public function __construct(private readonly Premium $premium, private readonly Entitlements $entitlements) {}

    /** The balance, the currency, the packs with their price and bonus, the last purchases, and the extras with their offers. */
    public function show(Request $request): JsonResponse
    {
        return $this->respond((int) $request->user()->getAuthIdentifier());
    }

    /** Buys a pack of charges of the auto-refill for coins (`pack`: its slug). `request_id` makes a repeated request harmless (`repeated`). */
    public function buyAutoRefill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'max:64'],
            'request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $bought = $this->entitlements->buyRefills($userId, $data['pack'], $data['request_id']);

        return $this->respond($userId, $bought['repeated'] ? 200 : 201, ['repeated' => $bought['repeated'], 'spent_coins' => $bought['coins'], 'bought_charges' => $bought['charges']]);
    }

    /** Switches the auto-refill on or off for the driver (`enabled`): whether the tank is filled for them when the fuel would run out. */
    public function setAutoRefill(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $this->entitlements->setAutoRefill($userId, (bool) $data['enabled']);

        return $this->respond($userId);
    }

    /** Buys the VIP account for coins, or extends it from its end. `request_id` makes a repeated request harmless (`repeated`). */
    public function buyVip(Request $request): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'string', 'max:'.RunService::MAX_REQUEST_ID_LENGTH]]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $bought = $this->entitlements->buyVip($userId, $data['request_id']);

        return $this->respond($userId, $bought['repeated'] ? 200 : 201, ['repeated' => $bought['repeated'], 'spent_coins' => $bought['coins']]);
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, int $status = 200, array $extra = []): JsonResponse
    {
        $data = [...$this->premium->view($userId), ...$this->entitlements->view($userId)];

        return response()->json(['data' => $data, ...$extra], $status)->header('Cache-Control', 'no-store');
    }
}
