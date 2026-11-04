<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Le bon de commande d'une clinique, en PDF, au fournisseur (canal « e-mail », 03.11.2026). */
class PurchaseOrderToSupplier extends Mailable
{
    use Queueable;

    public function __construct(public CommandeFournisseur $order, private string $pdfBytes) {}

    public function pdf(): string
    {
        return $this->pdfBytes;
    }

    private function clinique(): Clinique
    {
        return Clinique::withoutGlobalScopes()->findOrFail($this->order->clinique_id);
    }

    public function envelope(): Envelope
    {
        $clinique = $this->clinique();

        return new Envelope(
            subject: 'Commande '.$this->order->numero.' de '.$clinique->nom,
            replyTo: filled($clinique->email) ? [(string) $clinique->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.commandes.fournisseur', with: ['clinique' => $this->clinique()]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdfBytes, $this->order->numero.'.pdf')->withMime('application/pdf')];
    }
}
