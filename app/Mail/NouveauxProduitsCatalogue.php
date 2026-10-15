<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Import Swissmedic : nouveaux vaccins autorisés, dont le schéma reste à compléter. */
class NouveauxProduitsCatalogue extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  list<string>  $produits */
    public function __construct(public array $produits) {}

    public function envelope(): Envelope
    {
        $n = count($this->produits);

        return new Envelope(subject: "Catalogue des vaccins : {$n} nouveau".($n > 1 ? 'x' : '').' produit'.($n > 1 ? 's' : '').' autorisé'.($n > 1 ? 's' : ''));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.catalogue.nouveaux', with: ['produits' => $this->produits]);
    }
}
