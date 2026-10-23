<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What a driver can do with the data of their own account: take a copy of it, and have the account deleted. */
final class AccountDataController extends Controller
{
    /**
     * A copy of everything the game holds about the account of the driver, as JSON they can keep. It is the same copy the
     * operator would hand out on request, and it holds no secret. The answer is never kept by a cache, because it is
     * personal data, and it comes as a file, so that opening the address in a browser saves it.
     */
    public function export(Request $request, AccountExport $export): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            $export->for($user),
            200,
            ['Cache-Control' => 'no-store', 'Content-Disposition' => 'attachment; filename="ride-idle-data-'.$user->id.'.json"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
