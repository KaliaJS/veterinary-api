<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : la clinique répond à sa demande d'annulation (annulé, avec ou sans acompte rendu, ou maintenu). */
class AnnulationRendezVousDecidee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande, public ?CarbonInterface $debut) {}

    public function envelope(): Envelope
    {
        $clinique = $this->demande->clinique;

        return new Envelope(
            subject: ($this->demande->annulation_decision?->annule() ? 'Rendez-vous annulé chez ' : 'Rendez-vous maintenu chez ').$clinique->nom,
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        $fuseau = $this->demande->clinique->fuseau_horaire ?? 'Europe/Zurich';
        $chf = fn (?int $centimes) => chf(($centimes ?? 0) / 100);

        return new Content(markdown: 'emails.rendez-vous.annulation-decidee', with: [
            'quand' => $this->debut?->setTimezone($fuseau)->locale('fr')->isoFormat('dddd D MMMM [à] HH[:]mm'),
            'acompte' => $chf($this->demande->acompte_centimes),
            'rendu' => $chf($this->demande->acompte_rembourse_centimes),
            'garde' => $chf(max(0, (int) $this->demande->acompte_centimes - (int) $this->demande->acompte_rembourse_centimes)),
        ]);
    }
}
