<?php

namespace App\Mail;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  OrganizationInvitation  $invitation  The stored invitation (token is hashed).
     * @param  string  $rawToken  The unhashed token, only ever sent in this email.
     */
    public function __construct(
        public OrganizationInvitation $invitation,
        public string $rawToken,
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join '.$this->invitation->organization->name.' on ClaimTrail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            markdown: 'emails.invitations.organization',
            with: [
                'organizationName' => $this->invitation->organization->name,
                'roleLabel' => $this->invitation->role->label(),
                'inviterName' => $this->invitation->inviter?->name,
                'acceptUrl' => $frontend.'/invitations/accept?token='.$this->rawToken,
                'expiresAt' => $this->invitation->expires_at,
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
