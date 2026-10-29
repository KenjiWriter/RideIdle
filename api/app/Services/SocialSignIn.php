<?php

declare(strict_types=1);

namespace App\Services;

use App\Games\CurrentGame;
use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * The first two steps of linking an account to, or signing in with, Google, Facebook or Apple. Socialite sends the
 * browser to the provider and reads who the provider says the person is; what is done with that is a third step
 * (SocialAuthController::complete), which only the browser that started the flow can make.
 *
 * Without that, an attacker could start a flow of their own and make a victim finish it, so that the victim's Google
 * account is linked to the attacker's garage (login CSRF). So the browser gets a verifier when it starts, keeps it,
 * and has to show it when it finishes; the server holds only its hash. The callback therefore changes nothing: it
 * collects the result and waits.
 */
final class SocialSignIn
{
    public const LINK = 'link';

    public const LOGIN = 'login';

    /** How long the person has to come back from the provider. */
    private const STATE_TTL = 600;

    /** How long the result waits for the browser to finish. */
    private const RESULT_TTL = 300;

    public function __construct(private readonly AccountTokens $tokens) {}

    /**
     * Starts a flow: the address to send the browser to, and the verifier it must keep and show to finish it.
     * To link an account the flow is for the account that started it, and carries when the driver accepted the terms.
     *
     * @return array{url: string, verifier: string}
     */
    public function start(string $provider, string $intent, ?User $user = null, ?CarbonInterface $consentAt = null): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $state = $this->tokens->issue(AccountToken::OAUTH, self::STATE_TTL, [
            'user_id' => $user?->id,
            'provider' => $provider,
            'intent' => $intent,
            'consent_at' => $consentAt,
            'challenge' => AccountTokens::hash($verifier),
        ]);

        $driver = Socialite::driver($provider)->stateless()->with(['state' => $state]);

        // A game with a domain of its own has its own address registered with the provider.
        if (($callback = app(CurrentGame::class)->callbackUrl($provider)) !== null) {
            $driver->redirectUrl($callback);
        }

        // Apple puts a nonce into the identity token it signs and we check it: a token made for another flow is refused.
        if ($provider === AccountIdentity::APPLE) {
            $driver->setNonce(self::nonce($state));
        }

        return ['url' => $driver->redirect()->getTargetUrl(), 'verifier' => $verifier];
    }

    /**
     * What the provider sent the browser back with. Reads who the person is and keeps it for the browser that started
     * the flow. Changes nothing on any account.
     *
     * @return array{result: string, ticket?: string} `ready` with the ticket to finish with, or `cancelled` when the
     *                                                person said no at the provider, or `error`
     */
    public function collect(string $provider, Request $request): array
    {
        $state = (string) $request->input('state');
        $flow = $this->tokens->consume(AccountToken::OAUTH, $state);

        // The state is spent first, so that one answer cannot be used twice, and by the provider it was made for only.
        if ($flow === null || $flow->provider !== $provider) {
            return ['result' => 'error'];
        }

        if ($request->filled('error')) {
            return ['result' => 'cancelled'];
        }

        try {
            $driver = Socialite::driver($provider)->stateless();

            if (($callback = app(CurrentGame::class)->callbackUrl($provider)) !== null) {
                $driver->redirectUrl($callback);
            }

            if ($provider === AccountIdentity::APPLE) {
                $driver->setNonce(self::nonce($state));
            }

            $person = $driver->user();
        } catch (Throwable $exception) {
            report($exception);

            return ['result' => 'error'];
        }

        $subject = (string) $person->getId();

        if ($subject === '') {
            return ['result' => 'error'];
        }

        $email = $person->getEmail();

        $ticket = $this->tokens->issue(AccountToken::RESULT, self::RESULT_TTL, [
            'user_id' => $flow->user_id,
            'provider' => $provider,
            'intent' => $flow->intent,
            'consent_at' => $flow->consent_at,
            'challenge' => $flow->challenge,
            'subject' => $subject,
            'email' => is_string($email) && $email !== '' ? Accounts::normalizeEmail($email) : null,
        ]);

        return ['result' => 'ready', 'ticket' => $ticket];
    }

    /** The nonce of a flow, made from its state, so nothing but the state has to be kept. */
    public static function nonce(string $state): string
    {
        return hash_hmac('sha256', $state, 'oauth-nonce:'.config('app.key'));
    }
}
