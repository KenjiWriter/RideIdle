<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Services\AccountLinks;
use App\Services\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountEmailController extends Controller
{
    /**
     * Starts securing the account with an e-mail: sends a link that confirms it. Nothing changes until the link is
     * used, so an address typed with a typo or somebody else's secures nothing.
     *
     * The answer is the same whether the address is free or already has a garage, so a guest cannot use it to find out
     * who has an account. In the second case the link goes to the owner of the address, to the garage they have.
     * Securing needs the terms accepted and the age confirmed; when they did it is saved with the account.
     */
    public function store(Request $request, Accounts $accounts, AccountLinks $links): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'accept_terms' => ['accepted'],
            'confirm_age' => ['accepted'],
        ]);

        $user = $request->user();
        $email = Accounts::normalizeEmail($data['email']);

        if ($user->identities()->where('provider', AccountIdentity::EMAIL)->exists()) {
            return response()->json(['message' => __('account.email.already_linked'), 'code' => 'email_already_linked'], 409);
        }

        $owner = $accounts->userFor(AccountIdentity::EMAIL, $email);

        if ($owner === null) {
            $links->send(AccountLinkMail::CLAIM, AccountToken::CLAIM, $email, $user, $user->id, now());
        } else {
            $links->send(AccountLinkMail::EXISTING, AccountToken::LOGIN, $email, $owner, $owner->id);
        }

        return response()->json([
            'message' => __('account.email.sent', ['email' => $email, 'minutes' => intdiv(AccountLinks::ttlSeconds(), 60)]),
            'expires_in' => AccountLinks::ttlSeconds(),
        ], 202);
    }
}
