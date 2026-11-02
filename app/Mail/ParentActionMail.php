<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ParentActionMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $heading, public string $messageText, public string $actionUrl, public string $actionLabel = 'Continue', public ?string $permanentReturnUrl = null)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.parent-action');
    }
}
