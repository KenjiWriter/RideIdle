<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Mail\AccountLinkMail;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Services\AccountLinks;
use App\Services\AccountTokens;
use App\Services\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * Asks for a link that signs a driver in on a new device. The answer is always the same, whether the address
     * belongs to a garage or not, so nobody can use it to find out who plays: only the owner of the address gets a link.
     */
    public function requestLoginLink(Request $request, Accounts $accounts, AccountLinks $links): JsonResponse
    {
        $email = Accounts::normalizeEmail($request->validate(['email' => ['required', 'email:rfc', 'max:191']])['email']);

        $owner = $accounts->userFor(AccountIdentity::EMAIL, $email);

        if ($owner !== null) {
            $links->send(AccountLinkMail::LOGIN, AccountToken::LOGIN, $email, $owner, $owner->id);
        }

        return response()->json([
            'message' => __('account.login_link.sent', ['minutes' => intdiv(AccountLinks::ttlSeconds(), 60)]),
            'expires_in' => AccountLinks::ttlSeconds(),
        ], 202);
    }

    /**
     * Uses the link of an e-mail. The link is for one of two things, and the answer says which (`purpose`).
     *
     * A claim link secures the account the link was asked for with the address. It signs nobody in, because the link
     * may be opened in another browser than the one the guest plays in; the guest sees it on the next call. A login
     * link signs the driver in on this device and returns a token for it.
     *
     * The link works once and for a short time. A link that is unknown, expired, used or of another kind is refused
     * in the same way, so nobody can tell which it was.
     */
    public function confirm(Request $request, AccountTokens $tokens, Accounts $accounts): JsonResponse
    {
        $secret = $request->validate(['token' => ['required', 'string', 'max:128']])['token'];

        $token = $tokens->consume(AccountToken::CLAIM, $secret) ?? $tokens->consume(AccountToken::LOGIN, $secret);
        $user = $token?->user;

        if ($token === null || $user === null) {
            throw ValidationException::withMessages(['token' => __('account.token.invalid')]);
        }

        if ($token->purpose === AccountToken::CLAIM) {
            // Throws when the address or the provider was taken after the link was sent: the exceptions are answered as 409.
            $accounts->link($user, AccountIdentity::EMAIL, (string) $token->email, (string) $token->email, $token->consent_at);

            return response()->json(['purpose' => 'claim', 'data' => new MeResource($user)]);
        }

        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'purpose' => 'login',
            'data' => new MeResource($user),
            'token' => $user->createToken('email-login')->plainTextToken,
        ]);
    }
}
