<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SocialProviders;
use Illuminate\Http\JsonResponse;

final class AuthProviderController extends Controller
{
    /** The sign-ins with another service that are on, so a client offers only buttons that work. */
    public function index(SocialProviders $providers): JsonResponse
    {
        return response()->json(['data' => $providers->enabled()]);
    }
}
