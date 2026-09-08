<?php

namespace App\Services;

use App\Auth\ContexteProprietaire;
use App\Enums\CanalDevis;
use App\Enums\StatutCommandeBoutique;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Devis;
use App\Models\MessageClientele;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Vaccination;
use Illuminate\Support\Collection;

/**
 * Composition de l'accueil de l'espace propriétaire (écran « Accueil »
 * du proto) : prochain rendez-vous, rappels santé, choses à faire,
 * animaux, dernière ordonnance, dernière commande, clinique d'urgence.
 * Tout est dérivé ICI, toutes cliniques et organisations confondues —
 * le front affiche, il ne recalcule rien.
 */
class AccueilProprietaire
{
    /** Fenêtre des rappels santé : échéances à J+30, et tout retard. */
    public const int RAPPEL_JOURS = 30;

    /** @return array<string, mixed> */
    public function composer(ContexteProprietaire $contexte): array
    {
        $clientIds = $contexte->clientIds();
        $patientIds = $contexte->patientIds();

        $prochain = $this->prochainRendezVous($clientIds);
        $rappels = $this->rappels($patientIds);
        $devisASigner = $this->devisASigner($patientIds);
        $commandes = $this->commandes($clientIds);
        $commandesPretes = $commandes->where('statut', StatutCommandeBoutique::Prete);
        $derniereCommande = $commandes->first();

        $messagesNonLus = MessageClientele::query()
            ->where('user_id', $contexte->user()->id)
            ->where('auteur', 'clinique')
            ->whereNull('lu_le')
            ->count();

        return [
            'messages_non_lus' => $messagesNonLus,
            'prochain_rendez_vous' => $prochain ? $this->rendezVous($prochain) : null,
            'rappels' => $rappels->values()->all(),
            'a_faire' => $this->aFaire($devisASigner, $commandesPretes, $rappels, $messagesNonLus),
            'animaux' => Patient::withoutGlobalScopes()
                ->whereIn('client_id', $clientIds)
                ->orderBy('nom')
                ->get()
                ->map(fn (Patient $patient): array => $this->animal($patient))
                ->all(),
            'ordonnance' => $this->ordonnance($patientIds),
            'derniere_commande' => $derniereCommande ? $this->commande($derniereCommande) : null,
            'urgence' => $this->urgence($contexte, $prochain),
        ];
    }

    /** @param  list<int>  $clientIds */
    private function prochainRendezVous(array $clientIds): ?RendezVous
    {
        if ($clientIds === []) {
            return null;
        }

        return RendezVous::withoutGlobalScopes()
            ->with(['clinique', 'lignePlanning', 'patient'])
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function rendezVous(RendezVous $rendezVous): array
    {
        return [
            'id' => $rendezVous->id,
            'debute_le' => $rendezVous->starts_at?->toIso8601String(),
            'termine_le' => $rendezVous->ends_at?->toIso8601String(),
            'type' => $rendezVous->type?->value ?? $rendezVous->type,
            'motif' => $rendezVous->reason,
            'statut' => $rendezVous->status?->value ?? $rendezVous->status,
            'patient' => $rendezVous->patient ? ['id' => $rendezVous->patient->id, 'nom' => $rendezVous->patient->nom] : ['id' => null, 'nom' => $rendezVous->patient_name],
            'clinique' => ['id' => $rendezVous->clinique->id, 'nom' => $rendezVous->clinique->nom],
            'veterinaire' => $rendezVous->lignePlanning?->nom,
        ];
    }

    /**
     * Par animal et par vaccin, seule la DERNIÈRE injection compte : un
     * rappel fait rend l'échéance précédente caduque. Retards (jours
     * négatifs) et échéances à J+30, du plus urgent au moins urgent.
     *
     * @param  list<int>  $patientIds
     * @return Collection<int, array<string, mixed>>
     */
    private function rappels(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return new Collection;
        }

        $aujourdhui = today();
        $limite = $aujourdhui->copy()->addDays(self::RAPPEL_JOURS);

        return Vaccination::query()
            ->with('patient')
            ->whereIn('patient_id', $patientIds)
            ->whereNotNull('echeance_le')
            ->orderByDesc('injectee_le')
            ->get()
            ->unique(fn (Vaccination $vaccination): string => $vaccination->patient_id.'|'.mb_strtolower($vaccination->nom))
            ->filter(fn (Vaccination $vaccination): bool => $vaccination->echeance_le->lte($limite))
            ->sortBy(fn (Vaccination $vaccination) => $vaccination->echeance_le->timestamp)
            ->map(function (Vaccination $vaccination) use ($aujourdhui): array {
                $jours = (int) $aujourdhui->diffInDays($vaccination->echeance_le, false);

                return [
                    'vaccination_id' => $vaccination->id,
                    'vaccin' => $vaccination->nom,
                    'patient' => ['id' => $vaccination->patient->id, 'nom' => $vaccination->patient->nom],
                    'echeance_le' => $vaccination->echeance_le->toDateString(),
                    'jours' => $jours,
                    'est_en_retard' => $jours < 0,
                ];
            });
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, Devis>
     */
    private function devisASigner(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return new Collection;
        }

        return Devis::withoutGlobalScopes()
            ->with('patient')
            ->whereIn('patient_id', $patientIds)
            ->where('statut', StatutDevis::Envoye)
            ->where('canal', CanalDevis::Portail)
            ->whereNull('signe_le')
            ->where(fn ($q) => $q->whereNull('valide_jusquau')->orWhereDate('valide_jusquau', '>=', today()))
            ->orderBy('emis_le')
            ->get();
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<int, CommandeBoutique>
     */
    private function commandes(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return new Collection;
        }

        return CommandeBoutique::withoutGlobalScopes()
            ->with(['clinique', 'lignes.produit'])
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('passee_le')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, Devis>  $devis
     * @param  Collection<int, CommandeBoutique>  $commandesPretes
     * @param  Collection<int, array<string, mixed>>  $rappels
     * @return list<array<string, mixed>>
     */
    private function aFaire(Collection $devis, Collection $commandesPretes, Collection $rappels, int $messagesNonLus = 0): array
    {
        $items = [];

        foreach ($devis as $unDevis) {
            $items[] = [
                'type' => 'devis',
                'libelle' => sprintf('Signer le devis %s (CHF %s)', mb_strtolower($unDevis->motif ?? $unDevis->numero), number_format($unDevis->totalTtc(), 2, '.', ' ')),
                'cible' => 'documents',
                'devis_id' => $unDevis->id,
            ];
        }

        foreach ($commandesPretes as $commande) {
            $items[] = [
                'type' => 'commande',
                'libelle' => sprintf('Commande %s prête au retrait — %s', $commande->numero, $commande->clinique->nom),
                'cible' => 'commandes',
                'commande_id' => $commande->id,
            ];
        }

        if ($messagesNonLus > 0) {
            $items[] = [
                'type' => 'message',
                'libelle' => $messagesNonLus > 1 ? "{$messagesNonLus} nouveaux messages de la clinique" : 'Nouveau message de la clinique',
                'cible' => 'messages',
            ];
        }

        if ($rappels->isNotEmpty()) {
            $items[] = [
                'type' => 'rappel',
                'libelle' => sprintf('Réserver le rappel vaccin (%d)', $rappels->count()),
                'cible' => 'rendez-vous',
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function animal(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'nom' => $patient->nom,
            'espece' => $patient->espece?->value,
            'espece_libelle' => $patient->espece?->libelle(),
        ];
    }

    /**
     * @param  list<int>  $patientIds
     * @return array<string, mixed>|null
     */
    private function ordonnance(array $patientIds): ?array
    {
        if ($patientIds === []) {
            return null;
        }

        $ordonnance = Ordonnance::withoutGlobalScopes()
            ->with(['patient', 'employe', 'clinique', 'lignes.produit', 'demandesRenouvellement'])
            ->whereIn('patient_id', $patientIds)
            ->where('statut', StatutOrdonnance::Active)
            ->orderByDesc('signee_le')
            ->orderByDesc('id')
            ->first();

        if (! $ordonnance) {
            return null;
        }

        return [
            'id' => $ordonnance->id,
            'numero' => $ordonnance->numero,
            'libelle' => $ordonnance->lignes->map(fn ($ligne) => $ligne->produit?->nom)->filter()->implode(', ') ?: $ordonnance->numero,
            'patient' => ['id' => $ordonnance->patient->id, 'nom' => $ordonnance->patient->nom],
            'veterinaire' => $ordonnance->employe?->nom,
            'clinique' => ['id' => $ordonnance->clinique->id, 'nom' => $ordonnance->clinique->nom],
            'signee_le' => $ordonnance->signee_le?->toDateString(),
            'renouvellements' => $ordonnance->renouvellements,
            'renouvellement_en_attente' => $enAttente = $ordonnance->demandesRenouvellement->whereNull('traitee_le')->isNotEmpty(),
            'peut_renouveler' => $ordonnance->renouvellements > 0 && ! $enAttente,
        ];
    }

    /** @return array<string, mixed> */
    private function commande(CommandeBoutique $commande): array
    {
        return [
            'id' => $commande->id,
            'numero' => $commande->numero,
            'statut' => $commande->statut->value,
            'statut_libelle' => match ($commande->statut) {
                StatutCommandeBoutique::APreparer => 'En préparation',
                StatutCommandeBoutique::Prete => 'Prête au retrait',
                StatutCommandeBoutique::Retiree => 'Retirée',
            },
            'passee_le' => $commande->passee_le?->toIso8601String(),
            'articles' => $commande->lignes
                ->map(fn ($ligne) => ($ligne->produit?->nom ?? 'Article').($ligne->quantite > 1 ? ' ×'.$ligne->quantite : ''))
                ->implode(' · '),
            'total_ttc' => round($commande->totalTtc(), 2),
            'clinique' => ['id' => $commande->clinique->id, 'nom' => $commande->clinique->nom],
        ];
    }

    /**
     * Clinique d'urgence : celle du prochain rendez-vous, sinon celle du
     * dernier rendez-vous, sinon la première clinique de la première
     * organisation où le compte a une fiche.
     *
     * @return array<string, mixed>|null
     */
    private function urgence(ContexteProprietaire $contexte, ?RendezVous $prochain): ?array
    {
        $clinique = $prochain?->clinique;

        if (! $clinique && $contexte->clientIds() !== []) {
            $clinique = RendezVous::withoutGlobalScopes()
                ->with('clinique')
                ->whereIn('client_id', $contexte->clientIds())
                ->orderByDesc('starts_at')
                ->first()?->clinique;
        }

        if (! $clinique && ($organisation = $contexte->clients()->first()?->organisation)) {
            $clinique = Clinique::withoutGlobalScopes()
                ->whereHas('societe', fn ($q) => $q->where('organisation_id', $organisation->id))
                ->orderBy('id')
                ->first();
        }

        return $clinique ? ['clinique' => ['id' => $clinique->id, 'nom' => $clinique->nom], 'tel' => $clinique->tel] : null;
    }
}
