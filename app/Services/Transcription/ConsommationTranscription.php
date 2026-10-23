<?php

namespace App\Services\Transcription;

use App\Enums\StatutPartie;
use App\Enums\TypeAlerte;
use App\Mail\AlerteTranscription;
use App\Mail\PlafondTranscriptionOrganisation;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Minutes de transcription : ce qu'une organisation a consommé sur sa
 * période d'abonnement en cours (du jour anniversaire au suivant, facturée
 * au renouvellement), ce que ça coûte, et si elle peut encore enregistrer.
 * Il faut un abonnement en cours (tout l'espace clinique est payant), et
 * deux plafonds tiennent : celui de l'organisation (en francs par période,
 * dans ses réglages) et le budget Deepgram de la plateforme (en dollars par
 * mois, vide : illimité), qui prévient par e-mail à 80 % puis à 100 %. Les
 * administrateurs de l'organisation sont prévenus de même quand elle
 * approche de son propre plafond. Une partie déjà enregistrée est toujours
 * transcrite : les plafonds bloquent seulement le début d'un nouvel
 * enregistrement. Pas de TVA : Meteora Systems Sàrl n'y est pas assujettie.
 */
final class ConsommationTranscription
{
    public function tarif(): int
    {
        return (int) config('transcription.tarif_centimes_minute');
    }

    /** Minutes facturées : le total de la période arrondi à la minute supérieure. */
    public static function minutes(int $secondes): int
    {
        return (int) ceil($secondes / 60);
    }

    /** Montant en centimes. */
    public function montant(int $secondes): int
    {
        return self::minutes($secondes) * $this->tarif();
    }

    /**
     * Secondes transcrites entre deux instants : d'une organisation, ou de
     * toute la plateforme. Sans bornes : le mois civil en cours (heure suisse).
     */
    public function secondes(?int $organisationId = null, ?CarbonInterface $debut = null, ?CarbonInterface $fin = null): int
    {
        if (! $debut) {
            $debut = CarbonImmutable::now('Europe/Zurich')->startOfMonth();
            $fin = $debut->addMonthNoOverflow();
        }

        return (int) PartieEnregistrement::query()
            ->join('enregistrements_consultation', 'enregistrements_consultation.id', '=', 'parties_enregistrement.enregistrement_consultation_id')
            ->where('parties_enregistrement.statut', StatutPartie::Transcrite)
            ->where('parties_enregistrement.transcrite_le', '>=', CarbonImmutable::instance($debut)->utc())
            ->when($fin, fn ($requete) => $requete->where('parties_enregistrement.transcrite_le', '<', CarbonImmutable::instance($fin)->utc()))
            ->when($organisationId, fn ($requete) => $requete->where('enregistrements_consultation.organisation_id', $organisationId))
            ->sum('parties_enregistrement.duree_secondes');
    }

    /**
     * Période en cours de l'organisation : celle de son abonnement (minuit,
     * heure suisse), sinon le mois civil.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periode(Organisation $organisation): array
    {
        if ($organisation->periode_debut && in_array($organisation->statut_abonnement, ['actif', 'impaye'], true)) {
            $debut = CarbonImmutable::parse($organisation->periode_debut->toDateString(), 'Europe/Zurich');
            $fin = $organisation->periode_fin
                ? CarbonImmutable::parse($organisation->periode_fin->toDateString(), 'Europe/Zurich')
                : $debut->addMonthNoOverflow();

            return [$debut, $fin];
        }
        $debut = CarbonImmutable::now('Europe/Zurich')->startOfMonth();

        return [$debut, $debut->addMonthNoOverflow()];
    }

    /** Secondes de la période en cours de l'organisation. */
    public function secondesDeLaPeriode(Organisation $organisation): int
    {
        [$debut, $fin] = $this->periode($organisation);

        return $this->secondes($organisation->id, $debut, $fin);
    }

    /** Pourquoi l'organisation ne peut pas commencer un enregistrement, ou null. */
    public function refus(Organisation $organisation): ?string
    {
        $budget = config('transcription.budget_deepgram_usd');

        return match (true) {
            $organisation->statut_abonnement === 'impaye' => 'Une facture de votre abonnement attend son règlement : réglez-la dans les réglages de facturation pour utiliser la transcription.',
            $organisation->statut_abonnement !== 'actif' => 'La transcription demande un abonnement en cours.',
            $organisation->attendIdentite() => 'La transcription s’ouvre une fois l’identité de qui a inscrit la clinique vérifiée.',
            $this->montant($this->secondesDeLaPeriode($organisation)) >= $organisation->plafond_transcription => 'Le plafond de transcription de votre organisation est atteint pour cette période. Un administrateur peut le relever dans les réglages de l’organisation.',
            $budget !== null && $this->depenseDeepgram() >= $budget => 'La transcription est momentanément indisponible. Réessayez plus tard.',
            default => null,
        };
    }

    /** Dépense Deepgram estimée de la plateforme ce mois-ci, en dollars. */
    public function depenseDeepgram(): float
    {
        return round($this->secondes() / 60 * (float) config('transcription.cout_minute_deepgram_usd'), 2);
    }

    /** @return array<string, mixed> */
    public function etat(Organisation $organisation): array
    {
        [$debut] = $this->periode($organisation);
        $secondes = $this->secondes($organisation->id, ...$this->periode($organisation));
        $refus = $this->refus($organisation);

        return [
            'disponible' => $refus === null,
            'motif' => $refus,
            'periode_debut' => $debut->toDateString(),
            'minutes_periode' => self::minutes($secondes),
            'montant_periode' => $this->montant($secondes) / 100,
            'plafond' => $organisation->plafond_transcription / 100,
            'tarif_minute' => $this->tarif() / 100,
            'regles' => [
                ...config('transcription.enregistrement'),
                'morceau_max_ko' => (int) config('transcription.morceau_max_ko'),
            ],
        ];
    }

    /**
     * Après chaque transcription : les administrateurs de l'organisation
     * sont prévenus à 80 % puis à 100 % de leur plafond, une fois par seuil
     * et par période.
     */
    public function surveillerOrganisation(Organisation $organisation): void
    {
        $plafond = $organisation->plafond_transcription;
        if ($plafond <= 0) {
            return;
        }

        [$debut] = $this->periode($organisation);
        $montant = $this->montant($this->secondesDeLaPeriode($organisation));
        foreach ([100, 80] as $seuil) {
            if ($montant * 100 < $plafond * $seuil) {
                continue;
            }
            $cle = 'transcription-plafond-'.$organisation->id.'-'.$seuil.'-'.$debut->toDateString();
            if (Cache::add($cle, true, now()->addDays(400))) {
                $destinataires = $organisation->emailsAdministrateurs(TypeAlerte::Consommation);
                if ($destinataires !== []) {
                    Mail::to($destinataires)->send(new PlafondTranscriptionOrganisation($seuil, $montant / 100, $plafond / 100, $organisation->nom));
                }
            }

            return;
        }
    }

    /** Après chaque transcription : alerte quand la plateforme approche de son budget Deepgram. */
    public function surveiller(): void
    {
        $budget = config('transcription.budget_deepgram_usd');
        $destinataire = config('transcription.alerte_email');
        if ($budget === null || ! $destinataire) {
            return;
        }

        $depense = $this->depenseDeepgram();
        foreach ([100, 80] as $seuil) {
            if ($depense * 100 < $budget * $seuil) {
                continue;
            }
            if (Cache::add('transcription-alerte-'.$seuil.'-'.now('Europe/Zurich')->format('Y-m'), true, now()->addDays(40))) {
                Mail::to($destinataire)->send(new AlerteTranscription($seuil, $depense, (float) $budget, self::minutes($this->secondes())));
            }

            return;
        }
    }
}
