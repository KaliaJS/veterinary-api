<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Un panier automatique qui attend la clinique (03.11.2026) : sous le minimum
 * à sa date butoir (règle « demander »), ou prêt chez un fournisseur à qui la
 * clinique transmet elle-même le bon.
 */
class AutoBasketDecision extends Mailable
{
    use Queueable;

    public function __construct(public CommandeFournisseur $basket, public string $why) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Panier automatique '.$this->basket->fournisseur.' : à vous de dire');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.commandes.panier-decision', with: [
            'clinique' => Clinique::withoutGlobalScopes()->findOrFail($this->basket->clinique_id),
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/stocks',
        ]);
    }
}
