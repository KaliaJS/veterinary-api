<?php

namespace App\Services\Transcription;

use App\Enums\DomainePermission;
use App\Enums\StatutPartie;
use App\Mail\AlerteTranscription;
use App\Mail\PlafondTranscriptionOrganisation;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Minutes de transcription : ce qu'une organisation a consommé ce mois-ci
 * (heure suisse), ce que ça coûte, et si elle peut encore enregistrer.
 * Il faut un abonnement en cours (tout l'espace clinique est payant), et
 * deux plafonds tiennent : celui de l'organisation (en francs, dans ses
 * réglages) et le budget Deepgram de la plateforme (en dollars, vide :
 * illimité), qui prévient par e-mail à 80 % puis à 100 %. Les
 * administrateurs de l'organisation sont prévenus de même quand elle
 * approche de son propre plafond. Une partie déjà
 * enregistrée est toujours transcrite : les plafonds bloquent seulement le
 * début d'un nouvel enregistrement.
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
        $budget = config('transcription.budget_deepgram_usd');

        return match (true) {
            $organisation->statut_abonnement === 'impaye' => 'Une facture de votre abonnement attend son règlement : réglez-la dans les réglages de facturation pour utiliser la transcription.',
            ! $organisation->stripe_abonnement_id || $organisation->statut_abonnement !== 'actif' => 'La transcription demande un abonnement en cours.',
            $this->montant($this->secondes($organisation->id)) >= $organisation->plafond_transcription => 'Le plafond mensuel de transcription de votre organisation est atteint. Un administrateur peut le relever dans les réglages de l’organisation.',
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

    /**
     * Après chaque transcription : les administrateurs de l'organisation
     * (rôles du domaine organisation, accès actif) sont prévenus à 80 % puis
     * à 100 % de leur plafond mensuel, une fois par seuil et par mois.
     */
    public function surveillerOrganisation(Organisation $organisation): void
    {
        $plafond = $organisation->plafond_transcription;
        if ($plafond <= 0) {
            return;
        }

        $montant = $this->montant($this->secondes($organisation->id));
        foreach ([100, 80] as $seuil) {
            if ($montant * 100 < $plafond * $seuil) {
                continue;
            }
            $cle = 'transcription-plafond-'.$organisation->id.'-'.$seuil.'-'.now('Europe/Zurich')->format('Y-m');
            if (Cache::add($cle, true, now()->addDays(40))) {
                $destinataires = $this->administrateurs($organisation);
                if ($destinataires !== []) {
                    Mail::to($destinataires)->send(new PlafondTranscriptionOrganisation($seuil, $montant / 100, $plafond / 100, $organisation->nom));
                }
            }

            return;
        }
    }

    /** @return list<string> adresses des administrateurs de l'organisation */
    private function administrateurs(Organisation $organisation): array
    {
        $roles = Role::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->get()
            ->filter(fn (Role $role) => $role->domaines->contains(DomainePermission::Organisation))
            ->pluck('id');

        return Employe::withoutGlobalScopes()
            ->whereIn('role_id', $roles)
            ->where('acces_suspendu', false)
            ->with('user')
            ->get()
            ->map(fn (Employe $employe) => $employe->user?->email)
            ->filter()
            ->unique()
            ->values()
            ->all();
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
