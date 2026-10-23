<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The e-mail with the one-time link of the account flows. It comes in four kinds: `claim` confirms the e-mail of
 * a guest that wants to secure its garage, `add` confirms an e-mail that a secured account wants to add as one more
 * way to sign in, `login` signs a driver in who asked for a link, and `existing` goes to the owner of an e-mail that
 * somebody tried to use for a new garage, with a link to the garage it already has.
 */
final class AccountLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public const CLAIM = 'claim';

    public const ADD = 'add';

    public const LOGIN = 'login';

    public const EXISTING = 'existing';

    public function __construct(
        public readonly string $kind,
        public readonly string $url,
        public readonly string $driver,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.account_link.{$this->kind}.subject"));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-link', text: 'mail.account-link-text');
    }
}
