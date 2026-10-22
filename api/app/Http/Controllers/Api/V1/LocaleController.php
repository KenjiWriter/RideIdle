<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;

final class LocaleController extends Controller
{
    /** Języki obsługiwane przez grę, z nazwami własnymi do przełącznika w interfejsie. */
    public function index(): JsonResponse
    {
        $names = Locale::names();

        return response()->json([
            'data' => array_map(
                static fn (string $code): array => ['code' => $code, 'name' => $names[$code] ?? $code],
                Locale::supported(),
            ),
            'default' => Locale::default(),
            'current' => str_replace('_', '-', app()->getLocale()),
        ]);
    }
}
