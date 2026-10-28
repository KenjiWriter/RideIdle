<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AwaySummary;
use App\Services\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What happened to the whole account while the driver was away, and the driver saying they have seen it. */
final class AwayController extends Controller
{
    public function __construct(private readonly AwaySummary $away) {}

    /**
     * The summary of the break that waits for the driver: what each car earned, the jobs of the workshop that were done, the journeys that ended and the cars that
     * wait for the driver. `pending: false` when there is no break to tell of.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->away->summary($user), 'now_ms' => RunService::nowMs()])->header('Cache-Control', 'no-store');
    }

    /** The driver has seen the summary: the break is over, and the next one starts a new summary. */
    public function seen(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->away->dismiss($user);

        return response()->json(['data' => ['pending' => false], 'now_ms' => RunService::nowMs()]);
    }
}
