<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProviderAlreadyLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\AccountToken;
use App\Models\User;
use App\Services\Accounts;
use App\Services\AccountTokens;
use App\Services\SocialProviders;
use App\Services\SocialSignIn;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SocialAuthController extends Controller
{
    /**
     * Starts linking the account of the driver to a provider. The answer is the address to send the browser to and
     * the verifier the browser must keep until it comes back, because only the browser that started can finish.
     * Linking needs the terms accepted and the age confirmed, as securing an account with an e-mail does.
     */
    public function startLink(Request $request, string $provider, SocialProviders $providers, SocialSignIn $flow): JsonResponse
    {
        $request->validate(['accept_terms' => ['accepted'], 'confirm_age' => ['accepted']]);

        if (! $providers->isEnabled($provider)) {
            return $this->unavailable();
        }

        $user = $request->user();

        if ($user->identities()->where('provider', $provider)->exists()) {
            throw new ProviderAlreadyLinkedException($provider);
        }

        return response()->json($flow->start($provider, SocialSignIn::LINK, $user, now()));
    }

    /** Starts signing in with a provider on a device that has no account yet. The same answer as to link. */
    public function startLogin(string $provider, SocialProviders $providers, SocialSignIn $flow): JsonResponse
    {
        return $providers->isEnabled($provider) ? response()->json($flow->start($provider, SocialSignIn::LOGIN)) : $this->unavailable();
    }

    /**
     * Where the provider sends the person back to: Google and Facebook with a GET, Apple with a form post. The browser
     * goes on to the web app, with the outcome in the fragment, which is not sent to any server and not kept in logs.
     * Nothing changes on any account here.
     */
    public function callback(Request $request, string $provider, SocialProviders $providers, SocialSignIn $flow): RedirectResponse
    {
        $outcome = $providers->isEnabled($provider) ? $flow->collect($provider, $request) : ['result' => 'error'];

        return redirect()->away(config('ride.web_url').'/auth/callback#'.http_build_query([...$outcome, 'provider' => $provider]));
    }

    /**
     * Finishes a flow, which only the browser that started it can: it must show the verifier it was given then. What
     * the ticket from the callback is used for was fixed when the flow started.
     *
     * To link, the browser is signed in as the driver the flow was started for, and the provider is linked to that
     * account. To sign in, the provider must already be linked to an account; the answer is a token for this device.
     * A sign-in never creates an account and never looks an account up by the e-mail the provider reports.
     *
     * The ticket works once, and a wrong verifier does not spend it. The same answer is given for a ticket that is
     * unknown, expired, used or bound to another verifier, so nobody can tell which it was.
     */
    public function complete(Request $request, AccountTokens $tokens, Accounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:128'],
            'verifier' => ['required', 'string', 'max:128'],
        ]);

        $result = $tokens->consume(AccountToken::RESULT, $data['ticket'], $data['verifier']);

        if ($result === null) {
            throw ValidationException::withMessages(['ticket' => __('account.oauth.ticket_invalid')]);
        }

        // The route is open, so the driver is read from the device token if there is one.
        return $result->intent === SocialSignIn::LINK
            ? $this->link($request->user('sanctum'), $result, $accounts)
            : $this->signIn($result, $accounts);
    }

    private function link(?User $user, AccountToken $result, Accounts $accounts): JsonResponse
    {
        if ($user === null) {
            throw new AuthenticationException;
        }

        if ((int) $user->id !== (int) $result->user_id) {
            return response()->json(['message' => __('account.oauth.wrong_account'), 'code' => 'wrong_account'], 403);
        }

        // Throws when the provider belongs to another account, or this one has another of the kind: answered as 409.
        $accounts->link($user, (string) $result->provider, (string) $result->subject, $result->email, $result->consent_at);

        return response()->json(['purpose' => 'link', 'data' => new MeResource($user->unsetRelation('identities'))]);
    }

    private function signIn(AccountToken $result, Accounts $accounts): JsonResponse
    {
        $user = $accounts->userFor((string) $result->provider, (string) $result->subject);

        if ($user === null) {
            return response()->json([
                'message' => __('account.oauth.no_account', ['provider' => ucfirst((string) $result->provider)]),
                'code' => 'no_account',
            ], 404);
        }

        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'purpose' => 'login',
            'data' => new MeResource($user),
            'token' => $user->createToken($result->provider.'-login')->plainTextToken,
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('account.oauth.unavailable'), 'code' => 'provider_unavailable'], 404);
    }
}
