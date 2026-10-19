<?php

namespace App\Services\Transcription;

use App\Enums\StatutPartie;
use App\Models\FacturationTranscription;
use App\Models\Organisation;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Facture à chaque organisation les minutes transcrites d'un mois (heure
 * suisse), une seule fois par mois : total arrondi à la minute supérieure,
 * prix HT, TVA en sus. Avec un abonnement en cours, la ligne s'ajoute à sa
 * prochaine facture (« Transcriptions des consultations, octobre 2026 :
 * 312 min ») ; sinon une facture à part est prélevée sur la carte.
 */
final class FacturationTranscriptions
{
    public function __construct(
        private ConsommationTranscription $consommation,
        private PasserelleAbonnement $stripe,
        private AbonnementSaas $abonnements,
    ) {}

    /** @return list<FacturationTranscription> */
    public function facturerMois(CarbonInterface $mois): array
    {
        $debut = self::debut($mois);

        $organisations = DB::table('parties_enregistrement')
            ->join('enregistrements_consultation', 'enregistrements_consultation.id', '=', 'parties_enregistrement.enregistrement_consultation_id')
            ->where('parties_enregistrement.statut', StatutPartie::Transcrite->value)
            ->where('parties_enregistrement.transcrite_le', '>=', $debut->utc())
            ->where('parties_enregistrement.transcrite_le', '<', $debut->addMonthNoOverflow()->utc())
            ->distinct()
            ->pluck('enregistrements_consultation.organisation_id');

        $facturations = [];
        foreach (Organisation::query()->whereIn('id', $organisations)->get() as $organisation) {
            try {
                $facturation = $this->facturer($organisation, $debut);
            } catch (ErreurPaiement $erreur) {
                Log::warning('Transcriptions : paiement refusé.', ['organisation_id' => $organisation->id, 'mois' => $debut->format('Y-m'), 'erreur' => $erreur->getMessage()]);

                continue;
            }
            if ($facturation) {
                $facturations[] = $facturation;
            }
        }

        return $facturations;
    }

    public function facturer(Organisation $organisation, CarbonInterface $mois): ?FacturationTranscription
    {
        $debut = self::debut($mois);
        $deja = FacturationTranscription::query()
            ->where('organisation_id', $organisation->id)
            ->whereDate('mois', $debut->toDateString())
            ->first();
        if ($deja) {
            return $deja;
        }

        $secondes = $this->consommation->secondes($organisation->id, $debut);
        if ($secondes === 0) {
            return null;
        }

        $minutes = ConsommationTranscription::minutes($secondes);
        $montant = $this->consommation->montant($secondes);
        $libelle = 'Transcriptions des consultations, '.$debut->locale('fr_CH')->isoFormat('MMMM YYYY').' : '.$minutes.' min';
        $metadonnees = [
            'organisation_id' => (string) $organisation->id,
            'type' => 'transcription',
            'mois' => $debut->format('Y-m'),
            'minutes' => (string) $minutes,
            'libelle' => $libelle,
        ];
        $cle = 'transcription-'.$organisation->id.'-'.$debut->format('Y-m');

        $ligne = null;
        $facture = null;
        if ($organisation->stripe_abonnement_id && in_array($organisation->statut_abonnement, ['actif', 'impaye'], true)) {
            $ligne = $this->stripe->ajouterAuProchainCycle(
                $organisation->stripe_client_id, $organisation->stripe_abonnement_id, $montant, $libelle, $this->stripe->tauxTva(), $metadonnees, $cle,
            );
        } elseif ($organisation->stripe_client_id && $organisation->stripe_moyen_paiement_id) {
            $facture = $this->stripe->facturerAchat($organisation->stripe_client_id, $montant, $libelle, $this->stripe->tauxTva(), $metadonnees, $cle);
            $this->abonnements->refleterFacture($facture);
        } else {
            Log::warning('Transcriptions non facturables : ni abonnement ni carte.', ['organisation_id' => $organisation->id, 'mois' => $debut->format('Y-m')]);

            return null;
        }

        return FacturationTranscription::query()->create([
            'organisation_id' => $organisation->id,
            'mois' => $debut->toDateString(),
            'secondes' => $secondes,
            'minutes' => $minutes,
            'montant_centimes' => $montant,
            'stripe_ligne_id' => $ligne,
            'stripe_facture_id' => $facture['id'] ?? null,
        ]);
    }

    private static function debut(CarbonInterface $mois): CarbonImmutable
    {
        return CarbonImmutable::instance($mois)->setTimezone('Europe/Zurich')->startOfMonth();
    }
}
