<?php

namespace App\Services\Rappels;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Mail\RappelControle;
use App\Mail\RappelVaccin;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Rappels aux propriétaires par e-mail (décision de Jim, 28.10.2026).
 *
 * - Vaccin : la dernière injection de chaque vaccin d'un animal donne
 *   l'échéance ; le rappel part au délai réglé avant elle, une fois.
 * - Contrôle : un rendez-vous de contrôle prévu, au délai réglé avant lui.
 * - La clinique qui rappelle un vaccin est celle où l'animal a été vu en
 *   dernier (le carnet appartient à l'organisation) ; ses réglages comptent.
 * - Rien ne part : réglage coupé, rappels de l'animal coupés, animal
 *   décédé, propriétaire sans adresse ou qui a refusé (lien de l'e-mail ou
 *   son espace), ni la nuit (de 8 h à 20 h, à l'heure de la clinique).
 * - Chaque envoi est gardé ; un échec est retenté au plus trois fois.
 */
class RappelsProprietaires
{
    public const array DELAIS = [7, 3, 1];

    private const int HEURE_DEBUT = 8;

    private const int HEURE_FIN = 20;

    private const int TENTATIVES_MAX = 3;

    /** @return array{email: bool, sms: bool, delai_jours: int, vaccins: bool, controles: bool} */
    public static function reglages(Clinique $clinique): array
    {
        return [
            'email' => (bool) ($clinique->rappels_email ?? true),
            'sms' => (bool) ($clinique->rappels_sms ?? false),
            'delai_jours' => (int) ($clinique->rappels_delai_jours ?? 7),
            'vaccins' => (bool) ($clinique->rappels_vaccins ?? true),
            'controles' => (bool) ($clinique->rappels_controles ?? true),
        ];
    }

    /** Envoie les rappels dus à cette heure ; renvoie le nombre d'e-mails partis. */
    public function envoyerLesRappelsDus(): int
    {
        $partis = 0;
        foreach ($this->vaccinsDus() as [$vaccination, $clinique]) {
            $partis += $this->envoyerAutomatique($clinique, $vaccination->patient, 'vaccin', $vaccination, null) ? 1 : 0;
        }
        foreach ($this->controlesDus() as $rendezVous) {
            $partis += $this->envoyerAutomatique($rendezVous->clinique, $rendezVous->patient, 'controle', null, $rendezVous) ? 1 : 0;
        }

        return $partis;
    }

    /**
     * Rappel d'un vaccin demandé par la clinique (« Rappeler le
     * propriétaire ») : part tout de suite, gardé ; un refus dit pourquoi.
     *
     * @return array{statut: string, adresse: ?string, raison: ?string, rappel: ?RappelEnvoye}
     */
    public function rappelerMaintenant(Clinique $clinique, Vaccination $vaccination, ?User $auteur): array
    {
        $patient = $vaccination->patient;
        $client = $patient->client;
        $raison = match (true) {
            $patient->decede_le !== null => $patient->nom.' est décédé : aucun rappel ne part.',
            $client === null || blank($client->email) => 'Pas d’adresse e-mail pour '.($client ? self::nomDe($client) : 'ce propriétaire').'.',
            ! $this->accepteLesRappels($client) => self::nomDe($client).' a refusé les rappels par e-mail.',
            default => null,
        };
        if ($raison !== null) {
            return ['statut' => 'refuse', 'adresse' => null, 'raison' => $raison, 'rappel' => null];
        }

        $rappel = new RappelEnvoye([
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => 'vaccin',
            'vaccination_id' => $vaccination->id, 'libelle' => $vaccination->nom, 'echeance_le' => $vaccination->echeance_le ?? $vaccination->injectee_le,
            'canal' => 'email', 'manuel' => true, 'user_id' => $auteur?->id,
        ]);
        $this->expedier($rappel, $clinique, $client, $vaccination, null);

        return ['statut' => $rappel->statut, 'adresse' => $rappel->adresse, 'raison' => $rappel->statut === 'echec' ? 'L’e-mail n’est pas parti : réessayez dans un moment.' : null, 'rappel' => $rappel];
    }

    /**
     * Prochains rappels de la clinique (45 jours) : ce qui partira, et quand.
     *
     * @return list<array{objet: string, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, echeance_le: string, envoi_le: string, bloque: ?string}>
     */
    public function aVenir(Clinique $clinique): array
    {
        $reglages = self::reglages($clinique);
        $aujourdhui = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
        $limite = $aujourdhui->addDays(45);
        $lignes = [];

        if ($reglages['vaccins']) {
            foreach ($this->dernieresInjections($aujourdhui, $limite, $clinique->societe->organisation_id) as $vaccination) {
                if ($this->cliniqueDeReference($vaccination->patient)?->id !== $clinique->id || $this->dejaEnvoye('vaccin', $vaccination->id, $vaccination->echeance_le->toDateString())) {
                    continue;
                }
                $lignes[] = $this->ligneAVenir('vaccin', $vaccination->nom, $vaccination->patient, $vaccination->echeance_le->toDateString(), $reglages, $aujourdhui);
            }
        }
        if ($reglages['controles']) {
            $controles = RendezVous::withoutGlobalScopes()->with('patient.client')
                ->where('clinique_id', $clinique->id)->where('type', TypeRendezVous::Controle)->where('status', StatutRendezVous::Prevu)
                ->whereNotNull('patient_id')->whereBetween('starts_at', [now(), $limite->endOfDay()])->get();
            foreach ($controles as $rendezVous) {
                $jour = $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString();
                if (! $this->dejaEnvoye('controle', $rendezVous->id, $jour)) {
                    $lignes[] = $this->ligneAVenir('controle', 'Contrôle', $rendezVous->patient, $jour, $reglages, $aujourdhui);
                }
            }
        }

        usort($lignes, fn (array $a, array $b): int => [$a['envoi_le'], $a['echeance_le']] <=> [$b['envoi_le'], $b['echeance_le']]);

        return $lignes;
    }

    /** Le propriétaire n'a refusé les rappels ni par le lien de l'e-mail, ni dans son espace. */
    public function accepteLesRappels(Client $client): bool
    {
        if ($client->rappels_email === false) {
            return false;
        }

        return $client->user_id === null
            || PreferencesProprietaire::query()->where('user_id', $client->user_id)->value('rappels_email') !== false;
    }

    /** La clinique où l'animal a été vu en dernier ; sinon la première de son organisation. */
    public function cliniqueDeReference(Patient $patient): ?Clinique
    {
        $id = RendezVous::withoutGlobalScopes()->where('patient_id', $patient->id)
            ->where('status', '!=', StatutRendezVous::Annule)->where('starts_at', '<=', now())
            ->orderByDesc('starts_at')->value('clinique_id');

        return $id !== null
            ? Clinique::query()->withoutGlobalScopes()->find($id)
            : Clinique::query()->withoutGlobalScopes()->whereRelation('societe', 'organisation_id', $patient->organisation_id)->orderBy('id')->first();
    }

    public static function lienDesabonnement(Client $client): string
    {
        return URL::signedRoute('rappels.desabonner', ['client' => $client->id]);
    }

    public static function nomDe(Client $client): string
    {
        $nom = trim(($client->prenom ?? '').' '.($client->nom ?? ''));

        return $nom !== '' ? $nom : $client->nom_affiche;
    }

    /** @return Collection<int, array{0: Vaccination, 1: Clinique}> */
    private function vaccinsDus(): Collection
    {
        $aujourdhui = CarbonImmutable::parse(aujourdhui()->toDateString());

        return $this->dernieresInjections($aujourdhui->subDay(), $aujourdhui->addDays(max(self::DELAIS) + 1), null)
            ->map(fn (Vaccination $vaccination): ?array => ($clinique = $this->cliniqueDeReference($vaccination->patient)) ? [$vaccination, $clinique] : null)
            ->filter(function (?array $paire): bool {
                if ($paire === null) {
                    return false;
                }
                [$vaccination, $clinique] = $paire;
                $reglages = self::reglages($clinique);
                $jour = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());

                return $reglages['vaccins'] && $this->enJournee($clinique)
                    && $vaccination->echeance_le->toDateString() >= $jour->toDateString()
                    && $vaccination->echeance_le->toDateString() <= $jour->addDays($reglages['delai_jours'])->toDateString();
            })
            ->values();
    }

    /** @return Collection<int, RendezVous> */
    private function controlesDus(): Collection
    {
        return RendezVous::withoutGlobalScopes()->with(['clinique', 'patient.client'])
            ->where('type', TypeRendezVous::Controle)->where('status', StatutRendezVous::Prevu)->whereNotNull('patient_id')
            ->whereBetween('starts_at', [now(), now()->addDays(max(self::DELAIS) + 1)])
            ->get()
            ->filter(function (RendezVous $rendezVous): bool {
                $clinique = $rendezVous->clinique;
                $reglages = self::reglages($clinique);
                $jour = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
                $leJour = $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString();

                return $reglages['controles'] && $this->enJournee($clinique) && $leJour <= $jour->addDays($reglages['delai_jours'])->toDateString();
            })
            ->values();
    }

    /**
     * La dernière injection de chaque vaccin d'un animal, d'échéance dans la
     * période : un rappel fait depuis rend l'échéance précédente caduque.
     *
     * @return Collection<int, Vaccination>
     */
    private function dernieresInjections(CarbonImmutable $du, CarbonImmutable $au, ?int $organisationId): Collection
    {
        return Vaccination::query()->with('patient.client')
            ->whereNotNull('echeance_le')->whereBetween('echeance_le', [$du->toDateString(), $au->toDateString()])
            ->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->whereNull('decede_le')
                ->when($organisationId, fn ($requete, int $id) => $requete->where('organisation_id', $id)))
            ->get()
            ->filter(fn (Vaccination $vaccination): bool => ! Vaccination::query()
                ->where('patient_id', $vaccination->patient_id)
                ->whereRaw('lower(nom) = ?', [mb_strtolower($vaccination->nom)])
                ->where(fn ($plusRecente) => $plusRecente->where('injectee_le', '>', $vaccination->injectee_le)
                    ->orWhere(fn ($memeJour) => $memeJour->where('injectee_le', $vaccination->injectee_le)->where('id', '>', $vaccination->id)))
                ->exists())
            ->values();
    }

    private function envoyerAutomatique(Clinique $clinique, ?Patient $patient, string $objet, ?Vaccination $vaccination, ?RendezVous $rendezVous): bool
    {
        $client = $patient?->client;
        $reglages = self::reglages($clinique);
        if ($patient === null || $client === null || ! $reglages['email'] || $patient->rappels_coupes || $patient->decede_le !== null
            || blank($client->email) || ! $this->accepteLesRappels($client)) {
            return false;
        }

        $jour = $vaccination !== null
            ? $vaccination->echeance_le->toDateString()
            : $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString();
        $cle = $objet.':'.($vaccination->id ?? $rendezVous->id).':'.$jour.':email';
        $rappel = RappelEnvoye::query()->firstOrNew(['cle' => $cle]);
        if ($rappel->exists && ($rappel->statut === 'envoye' || $rappel->tentatives >= self::TENTATIVES_MAX)) {
            return false;
        }

        $rappel->fill([
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => $objet,
            'vaccination_id' => $vaccination?->id, 'rendez_vous_id' => $rendezVous?->id,
            'libelle' => $vaccination !== null ? $vaccination->nom : 'Contrôle', 'echeance_le' => $jour, 'canal' => 'email',
        ]);
        $this->expedier($rappel, $clinique, $client, $vaccination, $rendezVous);

        return $rappel->statut === 'envoye';
    }

    private function expedier(RappelEnvoye $rappel, Clinique $clinique, Client $client, ?Vaccination $vaccination, ?RendezVous $rendezVous): void
    {
        $lien = self::lienDesabonnement($client);
        $rappel->adresse = $client->email;
        $rappel->tentatives = (int) $rappel->tentatives + 1;
        try {
            Mail::to($client->email)->send($vaccination !== null
                ? new RappelVaccin($vaccination, $clinique, $client, $lien)
                : new RappelControle($rendezVous, $clinique, $client, $lien));
            $rappel->fill(['statut' => 'envoye', 'erreur' => null, 'envoye_le' => now()]);
        } catch (Throwable $e) {
            report($e);
            $rappel->fill(['statut' => 'echec', 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $rappel->save();
    }

    private function dejaEnvoye(string $objet, int $id, string $jour): bool
    {
        return RappelEnvoye::query()->where('cle', $objet.':'.$id.':'.$jour.':email')->where('statut', 'envoye')->exists();
    }

    private function enJournee(Clinique $clinique): bool
    {
        $heure = (int) now()->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->format('G');

        return $heure >= self::HEURE_DEBUT && $heure < self::HEURE_FIN;
    }

    /**
     * @param  array{email: bool, sms: bool, delai_jours: int, vaccins: bool, controles: bool}  $reglages
     * @return array{objet: string, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, echeance_le: string, envoi_le: string, bloque: ?string}
     */
    private function ligneAVenir(string $objet, string $libelle, Patient $patient, string $echeance, array $reglages, CarbonImmutable $aujourdhui): array
    {
        $client = $patient->client;
        $envoi = CarbonImmutable::parse($echeance)->subDays($reglages['delai_jours']);

        return [
            'objet' => $objet,
            'libelle' => $libelle,
            'patient' => ['id' => $patient->id, 'nom' => $patient->nom],
            'proprietaire' => $client ? self::nomDe($client) : null,
            'echeance_le' => $echeance,
            'envoi_le' => max($envoi, $aujourdhui)->toDateString(),
            // Ce qui l'empêchera de partir, dit tel quel
            'bloque' => match (true) {
                ! $reglages['email'] => 'Rappels par e-mail coupés',
                (bool) $patient->rappels_coupes => 'Rappels coupés pour '.$patient->nom,
                $client === null || blank($client->email) => 'Pas d’adresse e-mail',
                ! $this->accepteLesRappels($client) => 'Refusés par le propriétaire',
                default => null,
            },
        ];
    }
}
