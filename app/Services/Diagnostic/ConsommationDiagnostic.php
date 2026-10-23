<?php

namespace App\Services\Diagnostic;

use App\Enums\StatutSuggestionDiagnostique;
use App\Enums\TypeAlerte;
use App\Mail\PlafondDiagnosticOrganisation;
use App\Models\Organisation;
use App\Models\SuggestionDiagnostique;
use App\Services\Transcription\ConsommationTranscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Suggestions diagnostiques : ce qu'une organisation a demandé sur sa
 * période d'abonnement en cours (la même que pour la transcription),
 * ce que ça coûte, et si elle peut en demander encore. Seule une
 * suggestion rendue est facturée. Il faut un abonnement en cours et le
 * plafond de l'organisation (en francs par période, dans ses réglages)
 * tient ; ses administrateurs sont prévenus à 80 % puis à 100 %. Pas de
 * TVA : Meteora Systems Sàrl n'y est pas assujettie.
 */
final class ConsommationDiagnostic
{
    public function __construct(private ConsommationTranscription $transcription) {}

    public function tarif(): int
    {
        return (int) config('diagnostic.tarif_centimes');
    }

    /**
     * Suggestions rendues d'une organisation entre deux instants.
     *
     * @return Builder<SuggestionDiagnostique>
     */
    private function rendues(int $organisationId, CarbonImmutable $debut, ?CarbonImmutable $fin): Builder
    {
        return SuggestionDiagnostique::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('statut', StatutSuggestionDiagnostique::Prete->value)
            ->where('rendue_le', '>=', $debut->utc())
            ->when($fin, fn (Builder $requete) => $requete->where('rendue_le', '<', $fin->utc()));
    }

    /** @return array{nombre: int, montant: int} */
    public function dela(Organisation $organisation): array
    {
        [$debut, $fin] = $this->transcription->periode($organisation);
        $requete = $this->rendues($organisation->id, $debut, $fin);

        return ['nombre' => (clone $requete)->count(), 'montant' => (int) $requete->sum('montant_centimes')];
    }

    /** Pourquoi l'organisation ne peut pas demander de suggestion, ou null. */
    public function refus(Organisation $organisation): ?string
    {
        return match (true) {
            $organisation->statut_abonnement === 'impaye' => 'Une facture de votre abonnement attend son règlement : réglez-la dans les réglages de facturation pour utiliser les suggestions diagnostiques.',
            $organisation->statut_abonnement !== 'actif' => 'Les suggestions diagnostiques demandent un abonnement en cours.',
            $this->dela($organisation)['montant'] + $this->tarif() > $organisation->plafond_diagnostic => 'Le plafond des suggestions diagnostiques de votre organisation est atteint pour cette période. Un administrateur peut le relever dans les réglages.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function etat(Organisation $organisation): array
    {
        [$debut] = $this->transcription->periode($organisation);
        ['nombre' => $nombre, 'montant' => $montant] = $this->dela($organisation);
        $refus = $this->refus($organisation);

        return [
            'disponible' => $refus === null,
            'motif' => $refus,
            'periode_debut' => $debut->toDateString(),
            'suggestions_periode' => $nombre,
            'montant_periode' => $montant / 100,
            'plafond' => $organisation->plafond_diagnostic / 100,
            'tarif' => $this->tarif() / 100,
        ];
    }

    /**
     * Rendues et pas encore facturées jusqu'à `$fin` (y compris celles
     * d'une facture annulée depuis) : elles s'ajoutent à la prochaine.
     *
     * @return Collection<int, SuggestionDiagnostique>
     */
    public function aFacturer(Organisation $organisation, CarbonImmutable $fin): Collection
    {
        return SuggestionDiagnostique::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->where('statut', StatutSuggestionDiagnostique::Prete->value)
            ->whereNull('organisation_facture_id')
            ->where('montant_centimes', '>', 0)
            ->where('rendue_le', '<', $fin->utc())
            ->get(['id', 'montant_centimes']);
    }

    /**
     * Après chaque suggestion rendue : les administrateurs sont prévenus à
     * 80 % puis à 100 % du plafond, une fois par seuil et par période.
     */
    public function surveillerOrganisation(Organisation $organisation): void
    {
        $plafond = $organisation->plafond_diagnostic;
        if ($plafond <= 0) {
            return;
        }

        [$debut] = $this->transcription->periode($organisation);
        $montant = $this->dela($organisation)['montant'];
        foreach ([100, 80] as $seuil) {
            // 100 % : plus aucune suggestion ne tient sous le plafond
            $atteint = $seuil === 100 ? $montant + $this->tarif() > $plafond : $montant * 100 >= $plafond * $seuil;
            if (! $atteint) {
                continue;
            }
            $cle = 'diagnostic-plafond-'.$organisation->id.'-'.$seuil.'-'.$debut->toDateString();
            if (Cache::add($cle, true, now()->addDays(400))) {
                $destinataires = $organisation->emailsAdministrateurs(TypeAlerte::Consommation);
                if ($destinataires !== []) {
                    Mail::to($destinataires)->send(new PlafondDiagnosticOrganisation($seuil, $montant / 100, $plafond / 100, $organisation->nom));
                }
            }

            return;
        }
    }
}
