<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProviderAlreadyLinkedException;
use App\Http\Controllers\Controller;
use App\Services\SocialProviders;
use App\Services\SocialSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('account.oauth.unavailable'), 'code' => 'provider_unavailable'], 404);
    }
}
