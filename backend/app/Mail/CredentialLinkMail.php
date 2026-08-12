<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password-reset and account-setup links.
 *
 * Plain text on purpose. These go to parents on cheap Android handsets over
 * metered data, and a link that survives every mail client — including the ones
 * that strip HTML — matters more here than branding.
 *
 * A Mailable rather than `Mail::raw()` because raw sends are invisible to
 * `Mail::fake()`, so nothing could assert that a link was actually dispatched.
 */
class CredentialLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $body
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.credential-link');
    }
}
