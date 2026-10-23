<?php

namespace App\Jobs;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutSuggestionDiagnostique;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use App\Models\SuggestionDiagnostique;
use App\Services\Diagnostic\ConsommationDiagnostic;
use App\Services\Diagnostic\Suggesteur;
use App\Services\Diagnostic\SuggestionImpossible;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Demande à Claude la suggestion diagnostique d'un rendez-vous, à partir
 * du compte rendu envoyé par le vétérinaire, de la transcription de la
 * consultation s'il y en a une, du signalement et des comptes rendus
 * précédents du patient. Rendue, elle est facturée au tarif du moment ;
 * en échec, rien n'est dû.
 */
final class SuggererDiagnostic implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20];

    public int $timeout = 320;

    public function __construct(public int $suggestionId) {}

    /**
     * Jamais deux fois en même temps pour la même suggestion : une
     * tâche reprise par un second worker pendant que le premier attend
     * le fournisseur ne relance pas un appel payant (elle repasse plus
     * tard et trouve le travail fait).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('suggestion-diagnostique:'.$this->suggestionId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(Suggesteur $suggesteur, ConsommationDiagnostic $consommation): void
    {
        $suggestion = SuggestionDiagnostique::withoutGlobalScopes()->with('rendezVous.patient')->find($this->suggestionId);
        if (! $suggestion || $suggestion->statut !== StatutSuggestionDiagnostique::EnCours) {
            return;
        }

        $rendezVous = $suggestion->rendezVous;
        $segments = $suggestion->avec_transcription ? self::segments($rendezVous) : [];

        try {
            $resultat = $suggesteur->suggerer(
                $suggestion->soap,
                self::signalement($rendezVous),
                self::antecedents($rendezVous),
                $segments,
            );
        } catch (SuggestionImpossible $erreur) {
            report($erreur);
            $this->echec();

            return;
        }

        $suggestion->forceFill([
            'statut' => StatutSuggestionDiagnostique::Prete,
            'contenu' => $resultat->contenu,
            'modele' => $resultat->modele,
            'jetons_entree' => $resultat->jetonsEntree,
            'jetons_sortie' => $resultat->jetonsSortie,
            'montant_centimes' => $consommation->tarif(),
            'rendue_le' => now(),
        ])->save();

        $organisation = Organisation::query()->find($suggestion->organisation_id);
        if ($organisation) {
            $consommation->surveillerOrganisation($organisation);
        }
    }

    public function failed(?Throwable $erreur): void
    {
        $this->echec();
    }

    private function echec(): void
    {
        SuggestionDiagnostique::withoutGlobalScopes()
            ->whereKey($this->suggestionId)
            ->where('statut', StatutSuggestionDiagnostique::EnCours->value)
            ->update(['statut' => StatutSuggestionDiagnostique::Erreur->value, 'montant_centimes' => 0]);
    }

    /** La transcription du dernier enregistrement rédigé du rendez-vous. */
    public static function segments(?RendezVous $rendezVous): array
    {
        if (! $rendezVous) {
            return [];
        }
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()
            ->where('rendez_vous_id', $rendezVous->id)
            ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Valide->value])
            ->latest('id')
            ->with('parties')
            ->first();

        return $enregistrement?->parties
            ->flatMap(fn (PartieEnregistrement $partie) => collect($partie->segments ?? [])->map(fn (array $segment) => [
                'partie' => $partie->numero,
                'voix' => (int) $segment['voix'],
                'debut' => (float) $segment['debut'],
                'texte' => (string) $segment['texte'],
            ]))
            ->values()
            ->all() ?? [];
    }

    /**
     * Ce que le dossier dit de l'animal, sans son nom ni celui du propriétaire.
     *
     * @return array<string, string>
     */
    private static function signalement(?RendezVous $rendezVous): array
    {
        $patient = $rendezVous?->patient;
        $poids = $patient?->pesees()->orderByDesc('pesee_le')->orderByDesc('id')->first();

        return array_filter([
            'Espèce' => ($patient->espece ?? $rendezVous?->species)?->libelle(),
            'Race' => $patient?->race,
            'Sexe' => $patient?->sexe ? strtolower($patient->sexe->value).($patient->est_sterilise ? ', stérilisé' : ', non stérilisé') : null,
            'Âge' => $patient?->ne_le ? self::age($patient->ne_le) : null,
            'Poids' => $poids ? rtrim(rtrim(number_format((float) $poids->poids_kg, 2, '.', ''), '0'), '.').' kg, pesé le '.$poids->pesee_le->format('d.m.Y') : null,
            'Allergies connues' => $patient?->a_allergies ? 'oui, voir le dossier' : null,
            'Traitement en cours' => $patient?->traitement_en_cours,
            'Motif du rendez-vous' => $rendezVous?->reason,
        ], fn ($valeur) => is_string($valeur) && trim($valeur) !== '');
    }

    /**
     * Les derniers comptes rendus du patient, hors ce rendez-vous.
     *
     * @return list<array{date: string, motif: string, a: string, p: string}>
     */
    private static function antecedents(?RendezVous $rendezVous): array
    {
        if (! $rendezVous?->patient_id) {
            return [];
        }

        // Hors requête, aucun contexte ne filtre : l'organisation du rendez-vous
        // borne la recherche, explicitement (23.10.2026)
        $cliniques = Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $rendezVous->clinique()->withoutGlobalScopes()->first()?->societe?->organisation_id))
            ->pluck('id');

        return CompteRendu::query()
            ->join('rendez_vous', 'rendez_vous.id', '=', 'comptes_rendus.rendez_vous_id')
            ->whereIn('rendez_vous.clinique_id', $cliniques)
            ->where('rendez_vous.patient_id', $rendezVous->patient_id)
            ->where('rendez_vous.id', '!=', $rendezVous->id)
            ->where('rendez_vous.starts_at', '<', $rendezVous->starts_at)
            ->orderByDesc('rendez_vous.starts_at')
            ->limit((int) config('diagnostic.antecedents'))
            // Lignes de la jointure, pas des comptes rendus : lues telles quelles
            ->toBase()
            ->get(['comptes_rendus.a', 'comptes_rendus.p', 'rendez_vous.starts_at', 'rendez_vous.reason'])
            ->filter(fn ($cr) => trim((string) $cr->a) !== '' || trim((string) $cr->p) !== '')
            ->map(fn ($cr) => [
                'date' => $cr->starts_at ? CarbonImmutable::parse($cr->starts_at)->format('d.m.Y') : '',
                'motif' => trim((string) $cr->reason),
                'a' => trim((string) $cr->a),
                'p' => trim((string) $cr->p),
            ])
            ->values()
            ->all();
    }

    private static function age(CarbonInterface $naissance): string
    {
        $mois = (int) $naissance->diffInMonths(now());

        return $mois < 24 ? $mois.' mois' : intdiv($mois, 12).' ans';
    }
}
