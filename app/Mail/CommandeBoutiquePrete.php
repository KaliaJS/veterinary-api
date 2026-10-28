<?php

namespace App\Mail;

use App\Models\CommandeBoutique;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au client : sa commande de la boutique est prête à retirer (28.10.2026). */
class CommandeBoutiquePrete extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CommandeBoutique $commande) {}

    public function envelope(): Envelope
    {
        $clinique = $this->commande->clinique;

        return new Envelope(
            subject: 'Votre commande '.$this->commande->numero.' est prête chez '.$clinique->nom,
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.boutique.commande-prete');
    }
}
