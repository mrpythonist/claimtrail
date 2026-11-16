<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email twin of an in-app notification.
 *
 * Deliberately plain: a short title, a few lines of body, and a link
 * back to the app. No marketing layout, no tracking pixels. The mail
 * is sent by the SendNotificationEmail job, not directly, so the
 * notification row can be stamped once the mail is actually sent.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $notificationTitle,
        public string $notificationBody,
        public string $organizationName,
        public ?string $actionUrl = null,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.$this->organizationName.'] '.$this->notificationTitle,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notifications.notification',
            with: [
                'title' => $this->notificationTitle,
                'body' => $this->notificationBody,
                'organizationName' => $this->organizationName,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
