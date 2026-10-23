<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountErasure;
use App\Services\AccountExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * Deletes the account of the driver and everything about it, for good. The driver says they mean it by writing the
     * name of their driver, which a click by mistake does not do; case and the spaces around do not matter. The device
     * token goes with the account, so the next call from this device is a 401 and the app returns to the start.
     *
     * Whoever holds the device token can already do all that the account can, and this is not a way around that: it
     * only makes sure the deletion was meant. A copy of the data can be taken first (see `export`).
     */
    public function destroy(Request $request, AccountErasure $erasure): JsonResponse
    {
        $user = $request->user();

        $request->validate(['confirm_name' => ['required', 'string', 'max:64']]);

        if (User::nameKey($request->string('confirm_name')->toString()) !== User::nameKey($user->name)) {
            throw ValidationException::withMessages(['confirm_name' => __('account.delete.name_mismatch')]);
        }

        $erasure->erase($user);

        return response()->json(['deleted' => true]);
    }
}
