<?php

namespace App\Services;

use App\Enums\DrapeauRendezVous;
use App\Enums\MotifRefusDemande;
use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Mail\DemandeRendezVousAcceptee;
use App\Mail\DemandeRendezVousProposee;
use App\Mail\DemandeRendezVousRefusee;
use App\Models\DemandeRendezVous;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiements\AcomptesRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La réponse d'une clinique à une demande de rendez-vous en ligne, et
 * le choix du propriétaire parmi les horaires proposés. Chaque réponse
 * est gardée sur la demande (statut, horaires, motif) et écrite au
 * propriétaire par e-mail, avec le lien où il la suit. À appeler dans le
 * contexte de la clinique de la demande (planning, ou SousContexteClinique
 * depuis l'espace client et le lien sans compte).
 */
class TraitementDemande
{
    public function __construct(
        private CalculateurCreneaux $calculateur,
        private MutationRendezVous $mutation,
    ) {}

    /** Une ligne libre de ce début pour la durée (CalculateurCreneaux). */
    public function ligneLibreA(CarbonImmutable $debut, int $dureeMinutes): ?int
    {
        return $this->calculateur->ligneLibreA($debut, $dureeMinutes);
    }

    /**
     * Accepte la demande à ce début : le rendez-vous au planning (sur la
     * ligne donnée, sinon une ligne libre), la demande acceptée, le
     * propriétaire prévenu.
     */
    public function accepter(DemandeRendezVous $demande, CarbonImmutable $debut, ?int $ligneId, ?User $auteur): RendezVous
    {
        $this->doitEtreOuverte($demande);
        // Enregistré tel quel en base : en UTC, quel que soit le décalage reçu
        $debut = $debut->utc();

        if ($debut->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Cette heure est déjà passée.']);
        }

        $ligneId ??= $this->ligneLibreA($debut, $demande->duree_minutes);

        if ($ligneId === null) {
            throw ValidationException::withMessages(['starts_at' => 'Cette heure n’est plus libre au planning.']);
        }

        // L'animal du compte n'est pas forcément une fiche de la clinique :
        // le rendez-vous ne la référence que si elle en est une
        $patientId = $demande->patient_id !== null && Patient::query()->whereKey($demande->patient_id)->exists()
            ? $demande->patient_id
            : null;

        $attributs = $this->mutation->preparerCreation([
            'ligne_planning_id' => $ligneId,
            'starts_at' => $debut,
            'duree_minutes' => $demande->duree_minutes,
            'patient_id' => $patientId,
            'patient_name' => $demande->patient_name,
            'species' => $demande->species,
            'owner_name' => $demande->owner_name,
            'type' => $demande->teleconsultation ? TypeRendezVous::Teleconsultation : TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Prevu,
            'flags' => array_values(array_filter([
                $patientId === null && $demande->client_id === null ? DrapeauRendezVous::NouveauClient : null,
                $demande->acompteRetenu() > 0 ? DrapeauRendezVous::Acompte : null,
            ])),
            'reason' => $demande->reason,
        ]);
        $attributs['client_id'] ??= $demande->client_id;

        $rendezVous = DB::transaction(function () use ($demande, $attributs, $auteur): RendezVous {
            $rendezVous = $this->mutation->creer($attributs, $auteur);
            $demande->update([
                'statut' => StatutDemandeRendezVous::Acceptee,
                'rendez_vous_id' => $rendezVous->id,
                'traitee_le' => now(),
            ]);

            return $rendezVous;
        });

        $this->ecrire($demande, new DemandeRendezVousAcceptee($demande->load('clinique'), $rendezVous));

        return $rendezVous;
    }

    /**
     * Propose d'autres horaires (un à trois, libres au planning) : le
     * propriétaire choisit le sien depuis son espace ou le lien de l'e-mail.
     *
     * @param  list<CarbonImmutable>  $debuts
     */
    public function proposer(DemandeRendezVous $demande, array $debuts, ?string $message): void
    {
        $this->doitEtreOuverte($demande);

        foreach ($debuts as $i => $debut) {
            if ($debut->isPast() || $this->ligneLibreA($debut, $demande->duree_minutes) === null) {
                throw ValidationException::withMessages(["creneaux.$i" => 'Cette heure n’est plus libre au planning.']);
            }
        }

        $demande->update([
            'statut' => StatutDemandeRendezVous::Proposee,
            'creneaux_proposes' => collect($debuts)->sort()->map(fn (CarbonImmutable $debut): string => $debut->utc()->toIso8601String())->values()->all(),
            'message' => $message,
            'traitee_le' => now(),
            'jeton' => $demande->jeton ?? ($demande->user_id === null ? Str::random(48) : null),
        ]);

        $this->ecrire($demande, new DemandeRendezVousProposee($demande->load('clinique')));
    }

    public function refuser(DemandeRendezVous $demande, MotifRefusDemande $motif, ?string $message): void
    {
        $this->doitEtreOuverte($demande);

        // Un acompte payé (heure prise pendant le paiement) est rendu
        // d'abord : si le prestataire refuse, la demande reste ouverte et
        // l'on peut réessayer (le rendu n'est jamais fait deux fois)
        app(AcomptesRendezVous::class)->rembourser($demande);
        $demande->update([
            'statut' => StatutDemandeRendezVous::Refusee,
            'motif_refus' => $motif,
            'message' => $message,
            'traitee_le' => now(),
        ]);

        $this->ecrire($demande, new DemandeRendezVousRefusee($demande->load('clinique')));
    }

    /** Le propriétaire prend l'un des horaires proposés : la demande est acceptée à cette heure. */
    public function choisir(DemandeRendezVous $demande, CarbonImmutable $debut, ?User $auteur): RendezVous
    {
        if ($demande->statut !== StatutDemandeRendezVous::Proposee) {
            throw ValidationException::withMessages(['starts_at' => 'Cette demande n’attend plus de choix.']);
        }

        $estProposee = collect($demande->creneaux_proposes ?? [])
            ->contains(fn (string $iso): bool => CarbonImmutable::parse($iso)->equalTo($debut));

        if (! $estProposee) {
            throw ValidationException::withMessages(['starts_at' => 'Cette heure ne fait pas partie des propositions.']);
        }

        return $this->accepter($demande, $debut, null, $auteur);
    }

    private function doitEtreOuverte(DemandeRendezVous $demande): void
    {
        if (! $demande->statut->estOuverte()) {
            throw ValidationException::withMessages(['statut' => 'Cette demande a déjà reçu une réponse.']);
        }
    }

    private function ecrire(DemandeRendezVous $demande, Mailable $mail): void
    {
        $adresse = $demande->adresseDuProprietaire();

        if ($adresse) {
            Mail::to($adresse)->send($mail);
        }
    }
}
