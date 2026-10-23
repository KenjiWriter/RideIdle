<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorialResource;
use App\Services\Tutorial;
use App\Services\TutorialState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tutorial of a driver: the messages of the mentor that guide the first minutes.
 *
 * Looking at it brings it up to date, as looking at a run settles it, so the answer is true only at the moment of
 * the call and is never cached. Every answer is `data`: the status, the count of steps and the step that waits.
 */
final class TutorialController extends Controller
{
    public function __construct(private readonly Tutorial $tutorial) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->tutorial->evaluate($request->user()));
    }

    /** The driver answered the messages of the step that waits. Answering twice answers once. */
    public function reply(Request $request): JsonResponse
    {
        return $this->respond($this->tutorial->reply($request->user()));
    }

    /** The driver leaves the tutorial. The steps that are not done are left out, and so are their rewards. */
    public function skip(Request $request): JsonResponse
    {
        return $this->respond($this->tutorial->skip($request->user()));
    }

    private function respond(TutorialState $state): JsonResponse
    {
        return TutorialResource::make($state)->response()->header('Cache-Control', 'no-store');
    }
}
