<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    /** Saves the language of the account. Aliases such as br are stored as pt-BR. */
    public function updateLocale(Request $request): MeResource
    {
        $request->validate([
            'locale' => ['required', 'string', fn (string $attribute, mixed $value, Closure $fail) => Locale::normalize((string) $value) === null
                ? $fail('validation.in')->translate()
                : null],
        ]);

        $user = $request->user();
        $user->forceFill(['locale' => Locale::normalize($request->string('locale')->toString())])->save();

        return new MeResource($user);
    }
}
