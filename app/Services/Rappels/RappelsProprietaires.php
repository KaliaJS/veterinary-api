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
use App\Services\EtatVaccinal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** @var array<int, ?Clinique> */
    private array $cliniques = [];

    /** @var array<int, ?int> */
    private array $premiereClinique = [];

    /** @var array<int, bool> */
    private array $refusEspace = [];

    /** @var array<int, bool> */
    private array $organisationsOuvertes = [];

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
            $patient->decede_le !== null => 'Animal décédé : aucun rappel ne part.',
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
        $prochain = $this->prochainEnvoiPossible($clinique);
        $lignes = [];

        if ($reglages['vaccins']) {
            $vaccins = $this->dernieresInjections($aujourdhui, $limite, $clinique->societe->organisation_id);
            $references = $this->cliniquesDeReference($vaccins->pluck('patient'));
            $envoyes = $this->clesEnvoyees($vaccins->map(fn (Vaccination $v): string => self::cle('vaccin', $v->id, $v->echeance_le->toDateString())));
            $this->lireLesRefus($vaccins->map(fn (Vaccination $v): ?Client => $v->patient?->client));
            foreach ($vaccins as $vaccination) {
                if (($references[$vaccination->patient_id] ?? null)?->id !== $clinique->id
                    || isset($envoyes[self::cle('vaccin', $vaccination->id, $vaccination->echeance_le->toDateString())])) {
                    continue;
                }
                $echeance = $vaccination->echeance_le->toDateString();
                // Un vaccin se rappelle jusqu'au jour de son échéance compris
                $dernier = CarbonImmutable::parse($echeance.' 23:59:59', $clinique->fuseau_horaire ?: 'Europe/Zurich');
                $lignes[] = $this->ligneAVenir('vaccin', $vaccination->nom, $vaccination->patient, $echeance, $reglages, $prochain, $dernier);
            }
        }
        if ($reglages['controles']) {
            $controles = RendezVous::withoutGlobalScopes()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
                ->where('clinique_id', $clinique->id)->where('type', TypeRendezVous::Controle)->where('status', StatutRendezVous::Prevu)
                ->whereNotNull('patient_id')->whereBetween('starts_at', [now(), $limite->endOfDay()])->get();
            $envoyes = $this->clesEnvoyees($controles->map(fn (RendezVous $r): string => self::cle('controle', $r->id, $this->momentLocal($r, $clinique))));
            $this->lireLesRefus($controles->map(fn (RendezVous $r): ?Client => $r->patient?->client));
            foreach ($controles as $rendezVous) {
                if (! isset($envoyes[self::cle('controle', $rendezVous->id, $this->momentLocal($rendezVous, $clinique))])) {
                    $jour = substr($this->momentLocal($rendezVous, $clinique), 0, 10);
                    // Un contrôle se rappelle jusqu'à son heure
                    $lignes[] = $this->ligneAVenir('controle', 'Contrôle', $rendezVous->patient, $jour, $reglages, $prochain, $rendezVous->starts_at->toImmutable());
                }
            }
        }

        usort($lignes, fn (array $a, array $b): int => [$a['envoi_le'], $a['echeance_le']] <=> [$b['envoi_le'], $b['echeance_le']]);

        return $lignes;
    }

    /**
     * Les refus des espaces de ces propriétaires, lus en une requête (la
     * fenêtre des rappels en compte des dizaines).
     *
     * @param  iterable<?Client>  $clients
     */
    private function lireLesRefus(iterable $clients): void
    {
        $users = collect($clients)->filter()->pluck('user_id')->filter()->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->refusEspace))->values();
        if ($users->isEmpty()) {
            return;
        }
        $refus = PreferencesProprietaire::query()->whereIn('user_id', $users->all())->pluck('rappels_email', 'user_id');
        foreach ($users as $id) {
            $this->refusEspace[$id] = ($refus[$id] ?? null) !== false;
        }
    }

    /** Le propriétaire n'a refusé les rappels ni par le lien de l'e-mail, ni dans son espace. */
    public function accepteLesRappels(Client $client): bool
    {
        if ($client->rappels_email === false) {
            return false;
        }
        if ($client->user_id === null) {
            return true;
        }

        return $this->refusEspace[$client->user_id] ??= PreferencesProprietaire::query()->where('user_id', $client->user_id)->value('rappels_email') !== false;
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

    /** Un rappel automatique par échéance (le jour d'un vaccin, le moment d'un contrôle) et par canal. */
    private static function cle(string $objet, int $id, string $echeance): string
    {
        return $objet.':'.$id.':'.$echeance.':email';
    }

    /** Le moment d'un contrôle à l'heure de la clinique : un déplacement, même le jour même, est rappelé. */
    private function momentLocal(RendezVous $rendezVous, Clinique $clinique): string
    {
        return $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->format('Y-m-d H:i');
    }

    /**
     * @param  Collection<int, string>  $cles
     * @return array<string, true>
     */
    private function clesEnvoyees(Collection $cles): array
    {
        if ($cles->isEmpty()) {
            return [];
        }

        // Parti, en cours d'envoi, ou abandonné après trois échecs : plus « à venir »
        // (la liste des envois le montre) ; un échec qui sera repris l'est encore
        return array_fill_keys(RappelEnvoye::withoutGlobalScopes()->whereIn('cle', $cles->all())
            ->where(fn ($q) => $q->whereIn('statut', ['envoye', 'envoi'])->orWhere(fn ($e) => $e->where('statut', 'echec')->where('tentatives', '>=', self::TENTATIVES_MAX)))
            ->pluck('cle')->all(), true);
    }

    private function clinique(?int $id): ?Clinique
    {
        if ($id === null) {
            return null;
        }

        return $this->cliniques[$id] ??= Clinique::query()->withoutGlobalScopes()->with('societe.organisation')->find($id);
    }

    /**
     * La clinique où chaque animal a été vu en dernier (rendez-vous passé,
     * ni annulé ni manqué) ; sinon la première de son organisation. En une
     * requête pour tous les animaux.
     *
     * @param  Collection<int, Patient>  $patients
     * @return array<int, ?Clinique>
     */
    private function cliniquesDeReference(Collection $patients): array
    {
        $patients = $patients->filter()->unique('id');
        if ($patients->isEmpty()) {
            return [];
        }
        $vus = RendezVous::withoutGlobalScopes()->whereIn('patient_id', $patients->pluck('id')->all())
            ->whereNotIn('status', [StatutRendezVous::Annule, StatutRendezVous::Noshow])->where('starts_at', '<=', now())
            ->orderBy('starts_at')->get(['patient_id', 'clinique_id'])->keyBy('patient_id');
        $references = [];
        foreach ($patients as $patient) {
            $id = $vus[$patient->id]->clinique_id ?? ($this->premiereClinique[$patient->organisation_id] ??= Clinique::query()->withoutGlobalScopes()
                ->whereRelation('societe', 'organisation_id', $patient->organisation_id)->orderBy('id')->value('id'));
            $references[$patient->id] = $this->clinique($id);
        }

        return $references;
    }

    /** @return Collection<int, array{0: Vaccination, 1: Clinique}> */
    private function vaccinsDus(): Collection
    {
        $aujourdhui = CarbonImmutable::parse(aujourdhui()->toDateString());
        $vaccins = $this->dernieresInjections($aujourdhui->subDay(), $aujourdhui->addDays(max(self::DELAIS) + 1), null);
        $references = $this->cliniquesDeReference($vaccins->pluck('patient'));

        return $vaccins
            ->map(fn (Vaccination $vaccination): ?array => ($clinique = $references[$vaccination->patient_id] ?? null) ? [$vaccination, $clinique] : null)
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
        return RendezVous::withoutGlobalScopes()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
            ->where('type', TypeRendezVous::Controle)->where('status', StatutRendezVous::Prevu)->whereNotNull('patient_id')
            ->whereBetween('starts_at', [now(), now()->addDays(max(self::DELAIS) + 1)])
            ->get()
            ->filter(function (RendezVous $rendezVous): bool {
                $clinique = $this->clinique($rendezVous->clinique_id);
                if ($clinique === null) {
                    return false;
                }
                $rendezVous->setRelation('clinique', $clinique);
                $reglages = self::reglages($clinique);
                $jour = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
                $leJour = $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString();

                return $reglages['controles'] && $this->enJournee($clinique) && $leJour <= $jour->addDays($reglages['delai_jours'])->toDateString();
            })
            ->values();
    }

    /**
     * Les injections d'échéance dans la période qui sont encore dues : comme
     * le carnet, une injection plus récente qui couvre ses valences (même
     * vaccin, ou combiné qui le contient) rend l'échéance caduque. Toutes les
     * injections des animaux concernés sont lues en une fois.
     *
     * @return Collection<int, Vaccination>
     */
    private function dernieresInjections(CarbonImmutable $du, CarbonImmutable $au, ?int $organisationId): Collection
    {
        $candidates = Vaccination::query()
            ->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes(), 'produit.valences'])
            ->whereNotNull('echeance_le')->whereBetween('echeance_le', [$du->toDateString(), $au->toDateString()])
            ->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->whereNull('decede_le')
                ->when($organisationId, fn ($requete, int $id) => $requete->where('organisation_id', $id)))
            ->get();
        if ($candidates->isEmpty()) {
            return $candidates;
        }
        $parAnimal = Vaccination::query()->with('produit.valences')
            ->whereIn('patient_id', $candidates->pluck('patient_id')->unique()->all())->get()->groupBy('patient_id');

        return $candidates->filter(function (Vaccination $vaccination) use ($parAnimal): bool {
            $siennes = array_keys(EtatVaccinal::valencesDe($vaccination));
            $couvertes = [];
            foreach ($parAnimal[$vaccination->patient_id] ?? [] as $autre) {
                $plusRecente = $autre->injectee_le->gt($vaccination->injectee_le)
                    || ($autre->injectee_le->equalTo($vaccination->injectee_le) && $autre->id > $vaccination->id);
                if (! $plusRecente) {
                    continue;
                }
                if (mb_strtolower(trim($autre->nom)) === mb_strtolower(trim($vaccination->nom))) {
                    return false;
                }
                $couvertes += array_fill_keys(array_keys(EtatVaccinal::valencesDe($autre)), true);
            }

            return array_diff($siennes, array_keys($couvertes)) !== [];
        })->values();
    }

    private function envoyerAutomatique(Clinique $clinique, ?Patient $patient, string $objet, ?Vaccination $vaccination, ?RendezVous $rendezVous): bool
    {
        $client = $patient?->client;
        $reglages = self::reglages($clinique);
        // Une organisation fermée (abonnement terminé, vérification refusée) n'envoie plus
        $ouverte = $this->organisationsOuvertes[$clinique->id] ??= (bool) $clinique->societe?->organisation?->estOuverte();
        if (! $ouverte || $patient === null || $client === null || ! $reglages['email'] || $patient->rappels_coupes || $patient->decede_le !== null
            || blank($client->email) || ! $this->accepteLesRappels($client)) {
            return false;
        }

        $echeance = $vaccination !== null ? $vaccination->echeance_le->toDateString() : $this->momentLocal($rendezVous, $clinique);
        $rappel = $this->prendre(self::cle($objet, $vaccination->id ?? $rendezVous->id, $echeance), [
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => $objet,
            'vaccination_id' => $vaccination?->id, 'rendez_vous_id' => $rendezVous?->id,
            'libelle' => $vaccination !== null ? $vaccination->nom : 'Contrôle', 'echeance_le' => substr($echeance, 0, 10), 'canal' => 'email',
        ]);
        if ($rappel === null) {
            return false;
        }

        $this->expedier($rappel, $clinique, $client, $vaccination, $rendezVous);

        return $rappel->statut === 'envoye';
    }

    /**
     * Prend l'envoi avant d'envoyer (clé unique) : deux exécutions en même
     * temps n'envoient jamais deux fois. Un échec se reprend trois fois ; un
     * envoi resté « en cours » plus d'une heure (exécution interrompue) aussi.
     */
    /** @param  array<string, mixed>  $attributs */
    private function prendre(string $cle, array $attributs): ?RappelEnvoye
    {
        $existant = RappelEnvoye::withoutGlobalScopes()->where('cle', $cle)->first();
        if ($existant !== null) {
            $pris = RappelEnvoye::withoutGlobalScopes()->whereKey($existant->id)
                ->where(fn ($q) => $q->where(fn ($e) => $e->where('statut', 'echec')->where('tentatives', '<', self::TENTATIVES_MAX))
                    ->orWhere(fn ($e) => $e->where('statut', 'envoi')->where('updated_at', '<', now()->subHour())))
                ->update(['statut' => 'envoi', 'updated_at' => now()]);

            return $pris === 1 ? $existant->refresh() : null;
        }
        try {
            return RappelEnvoye::withoutGlobalScopes()->create([...$attributs, 'cle' => $cle, 'statut' => 'envoi', 'tentatives' => 0]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
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

    /** Le prochain moment où un rappel peut partir, à l'heure de la clinique (jamais la nuit). */
    private function prochainEnvoiPossible(Clinique $clinique): CarbonImmutable
    {
        $maintenant = CarbonImmutable::now($clinique->fuseau_horaire ?: 'Europe/Zurich');

        return match (true) {
            $maintenant->hour < self::HEURE_DEBUT => $maintenant->setTime(self::HEURE_DEBUT, 0),
            $maintenant->hour >= self::HEURE_FIN => $maintenant->addDay()->setTime(self::HEURE_DEBUT, 0),
            default => $maintenant,
        };
    }

    private function enJournee(Clinique $clinique): bool
    {
        $heure = (int) now()->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->format('G');

        return $heure >= self::HEURE_DEBUT && $heure < self::HEURE_FIN;
    }

    /**
     * @param  array{email: bool, sms: bool, delai_jours: int, vaccins: bool, controles: bool}  $reglages
     * @param  CarbonImmutable  $prochain  le prochain moment où un rappel peut partir
     * @param  CarbonImmutable  $dernier  le dernier moment où ce rappel a encore un sens
     * @return array{objet: string, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, echeance_le: string, envoi_le: string, bloque: ?string}
     */
    private function ligneAVenir(string $objet, string $libelle, Patient $patient, string $echeance, array $reglages, CarbonImmutable $prochain, CarbonImmutable $dernier): array
    {
        $client = $patient->client;
        $envoi = CarbonImmutable::parse($echeance)->subDays($reglages['delai_jours']);
        $aujourdhui = CarbonImmutable::parse($prochain->toDateString());

        return [
            'objet' => $objet,
            'libelle' => $libelle,
            'patient' => ['id' => $patient->id, 'nom' => $patient->nom],
            'proprietaire' => $client ? self::nomDe($client) : null,
            'echeance_le' => $echeance,
            'envoi_le' => max($envoi, $aujourdhui)->toDateString(),
            // Ce qui l'empêchera de partir, dit tel quel
            'bloque' => match (true) {
                $patient->decede_le !== null => 'Animal décédé',
                ! $reglages['email'] => 'Rappels par e-mail coupés',
                (bool) $patient->rappels_coupes => 'Rappels coupés pour '.$patient->nom,
                $client === null || blank($client->email) => 'Pas d’adresse e-mail',
                ! $this->accepteLesRappels($client) => 'Refusés par le propriétaire',
                // Saisi trop tard (après 20 h pour une échéance du jour) : il ne partira plus
                $prochain->greaterThan($dernier) => 'Échéance passée avant le prochain envoi',
                default => null,
            },
        ];
    }
}
