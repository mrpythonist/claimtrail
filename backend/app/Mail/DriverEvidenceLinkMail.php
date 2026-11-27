<?php

namespace App\Mail;

use App\Models\DriverEvidenceLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DriverEvidenceLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  DriverEvidenceLink  $link  The stored link (token is hashed).
     * @param  string  $rawToken  The unhashed token, only ever sent in this email.
     */
    public function __construct(
        public DriverEvidenceLink $link,
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
            subject: 'Submit evidence for '.$this->link->organization->name.' on ClaimTrail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $shipment = $this->link->shipment;
        $claim = $this->link->claim;

        return new Content(
            markdown: 'emails.communications.driver-evidence-link',
            with: [
                'organizationName' => $this->link->organization->name,
                'driverName' => $this->link->driver_name,
                'shipmentReference' => $shipment?->internal_reference ?? $shipment?->external_load_reference,
                'claimReference' => $claim?->claim_reference,
                'submitUrl' => $frontend.'/driver-evidence/'.$this->rawToken,
                'expiresAt' => $this->link->expires_at,
                'maxUses' => $this->link->max_uses,
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
