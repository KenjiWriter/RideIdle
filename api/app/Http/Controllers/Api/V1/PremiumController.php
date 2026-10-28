<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Premium;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The premium currency: the balance of the driver, the packs that are sold for real money and the last purchases. Buying is the next steps. */
final class PremiumController extends Controller
{
    public function __construct(private readonly Premium $premium) {}

    /** The balance, the currency, the packs with their price and bonus, and the last purchases of the driver. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->premium->view((int) $request->user()->getAuthIdentifier())]);
    }
}
