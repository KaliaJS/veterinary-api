<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\Patient;
use App\Models\ReferralInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Au confrère, ou à sa clinique : une lettre de référence l'attend dans
 * Veterinary (envoi C, 09.11.2026). Rien de médical dans l'e-mail :
 * l'animal, la clinique qui réfère, et le chemin vers ses messages, où il
 * accepte ou refuse.
 */
class ReferralInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ReferralInvitation $invitation, public Clinique $clinique, public Patient $patient, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->clinique->nom.' vous réfère '.$this->patient->nom);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.reference.invitation');
    }
}
