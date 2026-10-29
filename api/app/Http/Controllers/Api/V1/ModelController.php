<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModelController extends Controller
{
    /**
     * The model of the game that is live: its slots (`key`, the `role` that the formulas know it by or null, and whether the garage hides it) in the order the screens
     * list them, and the statistics of a car that the screens show, in their order. It is the same for everyone and changes only when another pack is made live, so a
     * copy is good for a minute and the `ETag` tells whether it is still the one.
     */
    public function show(Request $request, Rules $rules): JsonResponse
    {
        $response = response()->json($rules->model()->toArray());

        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }
}
