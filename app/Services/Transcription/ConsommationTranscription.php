<?php

namespace App\Services\Transcription;

use App\Enums\StatutPartie;
use App\Mail\AlerteTranscription;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Minutes de transcription : ce qu'une organisation a consommé ce mois-ci
 * (heure suisse), ce que ça coûte, et si elle peut encore enregistrer.
 * Deux plafonds : celui de l'organisation (en francs, dans ses réglages)
 * et celui de la plateforme (en minutes, Deepgram n'ayant pas de plafond
 * de dépense), qui prévient par e-mail à 80 % puis à 100 %. Une partie
 * déjà enregistrée est toujours transcrite : le plafond bloque seulement
 * le début d'un nouvel enregistrement.
 */
final class ConsommationTranscription
{
    public function tarif(): int
    {
        return (int) config('transcription.tarif_centimes_minute');
    }

    /** Minutes facturées : le total du mois arrondi à la minute supérieure. */
    public static function minutes(int $secondes): int
    {
        return (int) ceil($secondes / 60);
    }

    /** Montant HT en centimes. */
    public function montant(int $secondes): int
    {
        return self::minutes($secondes) * $this->tarif();
    }

    /** Secondes transcrites pendant le mois : d'une organisation, ou de toute la plateforme. */
    public function secondes(?int $organisationId = null, ?CarbonInterface $mois = null): int
    {
        $debut = CarbonImmutable::instance($mois ?? now())->setTimezone('Europe/Zurich')->startOfMonth();

        return (int) PartieEnregistrement::query()
            ->join('enregistrements_consultation', 'enregistrements_consultation.id', '=', 'parties_enregistrement.enregistrement_consultation_id')
            ->where('parties_enregistrement.statut', StatutPartie::Transcrite)
            ->where('parties_enregistrement.transcrite_le', '>=', $debut->utc())
            ->where('parties_enregistrement.transcrite_le', '<', $debut->addMonthNoOverflow()->utc())
            ->when($organisationId, fn ($requete) => $requete->where('enregistrements_consultation.organisation_id', $organisationId))
            ->sum('parties_enregistrement.duree_secondes');
    }

    /** Pourquoi l'organisation ne peut pas commencer un enregistrement, ou null. */
    public function refus(Organisation $organisation): ?string
    {
        return match (true) {
            ! $organisation->stripe_moyen_paiement_id => 'Enregistrez une carte dans les réglages de facturation pour utiliser la transcription.',
            $this->montant($this->secondes($organisation->id)) >= $organisation->plafond_transcription => 'Le plafond mensuel de transcription de votre organisation est atteint. Un administrateur peut le relever dans les réglages de l’organisation.',
            self::minutes($this->secondes()) >= (int) config('transcription.plafond_global_minutes') => 'La transcription est momentanément indisponible. Réessayez plus tard.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function etat(Organisation $organisation): array
    {
        $secondes = $this->secondes($organisation->id);
        $refus = $this->refus($organisation);

        return [
            'disponible' => $refus === null,
            'motif' => $refus,
            'minutes_mois' => self::minutes($secondes),
            'montant_mois' => $this->montant($secondes) / 100,
            'plafond' => $organisation->plafond_transcription / 100,
            'tarif_minute' => $this->tarif() / 100,
            'regles' => [
                ...config('transcription.enregistrement'),
                'morceau_max_ko' => (int) config('transcription.morceau_max_ko'),
            ],
        ];
    }

    /** Après chaque transcription : alerte quand la plateforme approche de son plafond. */
    public function surveiller(): void
    {
        $plafond = (int) config('transcription.plafond_global_minutes');
        $destinataire = config('transcription.alerte_email');
        if ($plafond <= 0 || ! $destinataire) {
            return;
        }

        $minutes = self::minutes($this->secondes());
        foreach ([100, 80] as $seuil) {
            if ($minutes * 100 < $plafond * $seuil) {
                continue;
            }
            if (Cache::add('transcription-alerte-'.$seuil.'-'.now('Europe/Zurich')->format('Y-m'), true, now()->addDays(40))) {
                Mail::to($destinataire)->send(new AlerteTranscription($seuil, $minutes, $plafond));
            }

            return;
        }
    }
}
