<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AccountLinkMail;
use App\Models\User;
use App\Support\Locale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the one-time link of an account flow by e-mail: a token for the purpose, and the e-mail with the address of
 * the web app that carries it. The e-mail is in the language of the account the link is about, and is queued, so a
 * slow mail server does not hold up the request.
 */
final class AccountLinks
{
    public function __construct(private readonly AccountTokens $tokens)
    {
    }

    public static function ttlSeconds(): int
    {
        return (int) config('ride.account.link_ttl_seconds');
    }

    /**
     * @param  string  $kind  the kind of e-mail, one of the constants of AccountLinkMail
     * @param  string  $purpose  what the link does when it is used, one of the constants of AccountToken
     * @param  User  $about  the account the e-mail speaks of: it sets the language and the name in the text
     * @param  int  $tokenUserId  the account the token is for
     */
    public function send(string $kind, string $purpose, string $email, User $about, int $tokenUserId, ?CarbonInterface $consentAt = null): void
    {
        $secret = $this->tokens->issue($purpose, self::ttlSeconds(), [
            'user_id' => $tokenUserId,
            'email' => $email,
            'consent_at' => $consentAt,
        ]);

        $url = config('ride.web_url').'/account/confirm?token='.$secret;

        Mail::to($email)
            ->locale(Locale::toLaravel(Locale::normalize($about->locale) ?? Locale::default()))
            ->queue(new AccountLinkMail($kind, $url, $about->name, intdiv(self::ttlSeconds(), 60)));
    }
}
