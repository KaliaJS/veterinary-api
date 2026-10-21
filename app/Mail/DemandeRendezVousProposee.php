<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : la clinique propose d'autres horaires, à choisir par le lien. */
class DemandeRendezVousProposee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande) {}

    public function envelope(): Envelope
    {
        $clinique = $this->demande->clinique;

        return new Envelope(
            subject: $clinique->nom.' vous propose d’autres horaires',
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        $fuseau = $this->demande->clinique->fuseau_horaire ?? 'Europe/Zurich';

        return new Content(markdown: 'emails.rendez-vous.demande-proposee', with: [
            'horaires' => collect($this->demande->creneaux_proposes ?? [])
                ->map(fn (string $iso): string => CarbonImmutable::parse($iso)->setTimezone($fuseau)->locale('fr')->isoFormat('dddd D MMMM [à] HH[:]mm'))
                ->all(),
            'lien' => $this->demande->lienDeSuivi(),
        ]);
    }
}
