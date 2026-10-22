<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGuestRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class PlayerController extends Controller
{
    /**
     * Creates a guest driver: no e-mail, no password, ready to play in seconds.
     * The returned token identifies this device; the account can be secured later.
     */
    public function store(CreateGuestRequest $request): JsonResponse
    {
        $name = trim($request->string('name')->toString());

        $user = new User(['name' => $name, 'locale' => $request->preferredLocale()]);
        $user->forceFill(['name_key' => User::nameKey($name), 'last_seen_at' => now()]);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same name raced past the validation: the database decides.
            throw ValidationException::withMessages([
                'name' => __('validation.unique', ['attribute' => 'name']),
            ]);
        }

        return response()->json([
            'data' => new MeResource($user),
            'token' => $user->createToken('guest-device')->plainTextToken,
        ], 201);
    }
}
