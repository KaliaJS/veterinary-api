<?php

namespace App\Mail;

use App\Models\CatalogueImport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Import du catalogue en échec, ou réussi avec un avertissement (03.11.2026). */
class CatalogueImportAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CatalogueImport $import) {}

    public function envelope(): Envelope
    {
        $what = $this->import->statut === CatalogueImport::ECHEC ? 'en échec' : 'avec un avertissement';

        return new Envelope(subject: 'Catalogue '.ucfirst($this->import->source).' : import '.$what);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.catalogue.alert', with: ['import' => $this->import]);
    }
}
