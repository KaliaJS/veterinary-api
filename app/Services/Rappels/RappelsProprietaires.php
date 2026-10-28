<?php

namespace App\Services\Rappels;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Mail\RappelControle;
use App\Mail\RappelVaccin;
use App\Mail\RelanceNoShow;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\SiteWeb;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\RappelSms;
use App\Services\EtatVaccinal;
use App\Services\Messages\QuotaSms;
use App\Support\Telephone;
use App\Support\TexteSms;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Twilio\TwilioChannel;
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

    /** La relance d'un rendez-vous manqué part le soir même, de 18 h à 22 h (décision de Jim, 28.10.2026) ; */
    private const int HEURE_RELANCE = 18;

    private const int HEURE_FIN_RELANCE = 22;

    /** marquée trop tard le soir, elle part le lendemain matin, avant midi. */
    private const int MIDI = 12;

    /** @var array<int, ?Clinique> */
    private array $cliniques = [];

    /** @var array<int, ?int> */
    private array $premiereClinique = [];

    /** @var array<int, bool> */
    private array $refusEspace = [];

    /** @var array<int, bool> */
    private array $organisationsOuvertes = [];

    /** @var array<int, bool> */
    private array $smsEspace = [];

    /** @return array{email: bool, sms: bool, sms_disponible: bool, delai_jours: int, vaccins: bool, controles: bool, relance_noshow: bool, messages_recus: bool, messages_reponses_seulement: bool} */
    public static function reglages(Clinique $clinique): array
    {
        return [
            'email' => (bool) ($clinique->rappels_email ?? true),
            'sms' => (bool) ($clinique->rappels_sms ?? false),
            // Les SMS peuvent partir (clés Twilio en place)
            'sms_disponible' => QuotaSms::estConfigure(),
            'delai_jours' => (int) ($clinique->rappels_delai_jours ?? 7),
            'vaccins' => (bool) ($clinique->rappels_vaccins ?? true),
            'controles' => (bool) ($clinique->rappels_controles ?? true),
            // Branchés le 28.10.2026 (décision de Jim)
            'relance_noshow' => (bool) ($clinique->relance_noshow ?? false),
            'messages_recus' => (bool) ($clinique->messages_recus ?? true),
            'messages_reponses_seulement' => (bool) ($clinique->messages_reponses_seulement ?? false),
        ];
    }

    /** Envoie les rappels dus à cette heure ; renvoie le nombre de rappels partis (par au moins un canal). */
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
     * @return array{statut: string, adresse: ?string, raison: ?string, manque?: ?string, rappel: ?RappelEnvoye}
     */
    public function rappelerMaintenant(Clinique $clinique, Vaccination $vaccination, ?User $auteur): array
    {
        $patient = $vaccination->patient;
        $client = $patient->client;
        // Les canaux choisis par le client, SMS compris (décision de Jim, 28.10.2026)
        $parEmail = $client !== null && filled($client->email) && $this->accepteLesRappels($client);
        $mobile = $client !== null ? $this->mobilePourSms($clinique, $client) : null;
        $raison = match (true) {
            $patient->decede_le !== null => 'Animal décédé : aucun rappel ne part.',
            $parEmail || $mobile !== null => null,
            $client === null => 'Pas d’adresse e-mail pour ce propriétaire.',
            default => $this->raisonSansCanal($clinique, $client),
        };
        if ($raison !== null) {
            return ['statut' => 'refuse', 'adresse' => null, 'raison' => $raison, 'rappel' => null];
        }

        $attributs = [
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => 'vaccin',
            'vaccination_id' => $vaccination->id, 'libelle' => $vaccination->nom, 'echeance_le' => $vaccination->echeance_le ?? $vaccination->injectee_le,
            'manuel' => true, 'user_id' => $auteur?->id,
        ];
        $partis = [];
        if ($parEmail) {
            $rappel = new RappelEnvoye([...$attributs, 'canal' => 'email']);
            $this->expedier($rappel, $clinique, $client, $vaccination, null);
            $partis[] = $rappel;
        }
        if ($mobile !== null) {
            $rappel = new RappelEnvoye([...$attributs, 'canal' => 'sms']);
            $this->expedierSms($rappel, $clinique, $mobile, self::texteSms($clinique, $patient, $vaccination, null));
            $partis[] = $rappel;
        }
        $envoyes = array_values(array_filter($partis, fn (RappelEnvoye $r): bool => $r->statut === 'envoye'));
        $manques = array_filter($partis, fn (RappelEnvoye $r): bool => $r->statut !== 'envoye');

        return [
            'statut' => $envoyes === [] ? 'echec' : 'envoye',
            // E-mail parti, SMS non (ou l'inverse) : le carnet le dit aussi
            'manque' => $envoyes === [] || $manques === [] ? null : implode(' ', array_map(
                fn (RappelEnvoye $r): string => ($r->canal === 'sms' ? 'Le SMS' : 'L’e-mail').' n’est pas parti'.(str_starts_with((string) $r->erreur, 'Quota') ? ', '.mb_lcfirst((string) $r->erreur) : '.'),
                $manques,
            )),
            'adresse' => $envoyes === [] ? null : implode(' et ', array_map(fn (RappelEnvoye $r): string => (string) $r->adresse, $envoyes)),
            'raison' => $envoyes === [] ? 'Le rappel n’est pas parti : réessayez dans un moment.' : null,
            'rappel' => $envoyes[0] ?? $partis[0] ?? null,
        ];
    }

    /**
     * Prochains rappels de la clinique (45 jours) : ce qui partira, et quand.
     *
     * @return list<array{objet: string, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, echeance_le: string, envoi_le: string, canaux: list<string>, bloque: ?string}>
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
            $envoyes = $this->clesEnvoyees($vaccins->flatMap(fn (Vaccination $v): array => self::cles('vaccin', $v->id, $v->echeance_le->toDateString())));
            $this->lireLesRefus($vaccins->map(fn (Vaccination $v): ?Client => $v->patient?->client));
            foreach ($vaccins as $vaccination) {
                if (($references[$vaccination->patient_id] ?? null)?->id !== $clinique->id
                    || self::dejaParti($envoyes, 'vaccin', $vaccination->id, $vaccination->echeance_le->toDateString())) {
                    continue;
                }
                $echeance = $vaccination->echeance_le->toDateString();
                // Un vaccin se rappelle jusqu'au jour de son échéance compris
                $dernier = CarbonImmutable::parse($echeance.' 23:59:59', $clinique->fuseau_horaire ?: 'Europe/Zurich');
                $lignes[] = $this->ligneAVenir($clinique, 'vaccin', $vaccination->nom, $vaccination->patient, $echeance, $reglages, $prochain, $dernier);
            }
        }
        if ($reglages['controles']) {
            $controles = RendezVous::withoutGlobalScopes()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
                ->where('clinique_id', $clinique->id)->where('type', TypeRendezVous::Controle)->where('status', StatutRendezVous::Prevu)
                ->whereNotNull('patient_id')->whereBetween('starts_at', [now(), $limite->endOfDay()])->get();
            $envoyes = $this->clesEnvoyees($controles->flatMap(fn (RendezVous $r): array => self::cles('controle', $r->id, $this->momentLocal($r, $clinique))));
            $this->lireLesRefus($controles->map(fn (RendezVous $r): ?Client => $r->patient?->client));
            foreach ($controles as $rendezVous) {
                if (! self::dejaParti($envoyes, 'controle', $rendezVous->id, $this->momentLocal($rendezVous, $clinique))) {
                    $jour = substr($this->momentLocal($rendezVous, $clinique), 0, 10);
                    // Un contrôle se rappelle jusqu'à son heure
                    $lignes[] = $this->ligneAVenir($clinique, 'controle', 'Contrôle', $rendezVous->patient, $jour, $reglages, $prochain, $rendezVous->starts_at->toImmutable());
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
        // Les choix des espaces (e-mail, SMS) en une requête
        $preferences = PreferencesProprietaire::query()->whereIn('user_id', $users->all())->get(['user_id', 'rappels_email', 'rappels_sms'])->keyBy('user_id');
        foreach ($users as $id) {
            $this->refusEspace[$id] = ($preferences[$id]->rappels_email ?? null) !== false;
            $this->smsEspace[$id] = (bool) ($preferences[$id]->rappels_sms ?? false);
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
    private static function cle(string $objet, int $id, string $echeance, string $canal = 'email'): string
    {
        return $objet.':'.$id.':'.$echeance.':'.$canal;
    }

    /** @return list<string> */
    private static function cles(string $objet, int $id, string $echeance): array
    {
        return [self::cle($objet, $id, $echeance, 'email'), self::cle($objet, $id, $echeance, 'sms')];
    }

    /** @param  array<string, mixed>  $envoyes */
    private static function dejaParti(array $envoyes, string $objet, int $id, string $echeance): bool
    {
        return collect(self::cles($objet, $id, $echeance))->contains(fn (string $cle): bool => isset($envoyes[$cle]));
    }

    /**
     * Le propriétaire veut ses rappels par SMS : son choix dans son espace
     * s'il a un compte, sinon la case cochée par la clinique sur sa fiche.
     */
    public function veutLesSms(Client $client): bool
    {
        if ($client->user_id === null) {
            return (bool) $client->rappels_sms;
        }

        return $this->smsEspace[$client->user_id] ??= (bool) PreferencesProprietaire::query()->where('user_id', $client->user_id)->value('rappels_sms');
    }

    /** Le mobile où envoyer le SMS, si la clinique l'utilise et que le client le veut ; null sinon. */
    private function mobilePourSms(Clinique $clinique, Client $client): ?string
    {
        if (! self::reglages($clinique)['sms'] || ! QuotaSms::estConfigure() || ! $this->veutLesSms($client)) {
            return null;
        }

        return Telephone::mobile($client->tel);
    }

    /** Pourquoi aucun rappel ne peut partir vers ce client (ni e-mail, ni SMS). */
    private function raisonSansCanal(Clinique $clinique, Client $client): string
    {
        $nom = self::nomDe($client);
        if ($this->veutLesSms($client) && ! $this->accepteLesRappels($client)) {
            return match (true) {
                ! self::reglages($clinique)['sms'] || ! QuotaSms::estConfigure() => $nom.' a choisi les SMS seulement, et la clinique n’en envoie pas.',
                default => 'Pas de numéro de mobile pour '.$nom.'.',
            };
        }

        return blank($client->email) ? 'Pas d’adresse e-mail pour '.$nom.'.' : $nom.' a refusé les rappels par e-mail.';
    }

    /** Le texte du SMS : court (160 caractères), sans détail médical, la clinique et son téléphone. */
    public static function texteSms(Clinique $clinique, Patient $patient, ?Vaccination $vaccination, ?RendezVous $rendezVous): string
    {
        $tel = filled($clinique->tel) ? ' Rendez-vous : '.$clinique->tel.'.' : '';
        if ($vaccination !== null) {
            return $clinique->nom.' : le vaccin '.$vaccination->nom.' de '.$patient->nom.' est à faire avant le '.($vaccination->echeance_le ?? $vaccination->injectee_le)->format('d.m.Y').'.'.$tel;
        }
        $moment = $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich');

        return $clinique->nom.' : contrôle de '.$patient->nom.' le '.$moment->format('d.m.Y').' à '.$moment->format('H:i').'.'.(filled($clinique->tel) ? ' Pour le déplacer : '.$clinique->tel.'.' : '');
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
        if (! $ouverte || $patient === null || $client === null || $patient->rappels_coupes || $patient->decede_le !== null) {
            return false;
        }

        $echeance = $vaccination !== null ? $vaccination->echeance_le->toDateString() : $this->momentLocal($rendezVous, $clinique);
        $id = $vaccination->id ?? $rendezVous->id;
        $attributs = [
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => $objet,
            'vaccination_id' => $vaccination?->id, 'rendez_vous_id' => $rendezVous?->id,
            'libelle' => $vaccination !== null ? $vaccination->nom : 'Contrôle', 'echeance_le' => substr($echeance, 0, 10),
        ];
        $parti = false;

        // Chaque canal choisi par le client et permis par la clinique (28.10.2026)
        if ($reglages['email'] && filled($client->email) && $this->accepteLesRappels($client)) {
            $rappel = $this->prendre(self::cle($objet, $id, $echeance, 'email'), [...$attributs, 'canal' => 'email']);
            if ($rappel !== null) {
                $this->expedier($rappel, $clinique, $client, $vaccination, $rendezVous);
                $parti = $rappel->statut === 'envoye';
            }
        }
        $mobile = $this->mobilePourSms($clinique, $client);
        if ($mobile !== null) {
            $rappel = $this->prendre(self::cle($objet, $id, $echeance, 'sms'), [...$attributs, 'canal' => 'sms']);
            if ($rappel !== null) {
                $this->expedierSms($rappel, $clinique, $mobile, self::texteSms($clinique, $patient, $vaccination, $rendezVous));
                $parti = $parti || $rappel->statut === 'envoye';
            }
        }

        return $parti;
    }

    /**
     * Relance après un rendez-vous manqué (décision de Jim, 28.10.2026) : le
     * soir même, un lien pour reprendre rendez-vous, par chaque canal choisi
     * par le client et permis par la clinique ; une seule fois.
     */
    public function envoyerLesRelancesNoShow(): int
    {
        $partis = 0;
        $manques = RendezVous::withoutGlobalScopes()
            ->with(['clinique' => fn ($q) => $q->withoutGlobalScopes(), 'patient' => fn ($q) => $q->withoutGlobalScopes(), 'patient.client' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('status', StatutRendezVous::Noshow)
            ->whereNotNull('patient_id')
            ->whereBetween('starts_at', [now()->subHours(48), now()])
            ->get();
        foreach ($manques as $rendezVous) {
            $clinique = $rendezVous->clinique;
            $local = now()->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich');
            $jour = $rendezVous->starts_at->setTimezone($local->getTimezone())->toDateString();
            // Le soir même, ou le lendemain matin si l'absence a été marquée trop tard
            $ceSoir = $jour === $local->toDateString() && $local->hour >= self::HEURE_RELANCE && $local->hour < self::HEURE_FIN_RELANCE;
            $demainMatin = $jour === $local->subDay()->toDateString() && $local->hour >= self::HEURE_DEBUT && $local->hour < self::MIDI;
            if (! $clinique->relance_noshow || (! $ceSoir && ! $demainMatin)) {
                continue;
            }
            $partis += $this->envoyerRelance($clinique, $rendezVous) ? 1 : 0;
        }

        return $partis;
    }

    /**
     * Où reprendre rendez-vous : le site publié de l'organisation, sinon la
     * clinique dans l'annuaire ; null si aucune page ne la montre (le
     * message donne alors son téléphone).
     */
    public static function lienReprise(Clinique $clinique): ?string
    {
        $site = SiteWeb::query()->withoutGlobalScopes()->where('organisation_id', $clinique->societe?->organisation_id)->where('est_publie', true)->first();
        if ($site !== null) {
            return $site->urlPublique();
        }

        return $clinique->estDansLAnnuaire() ? rtrim((string) config('app.frontend_url'), '/').'/cliniques/'.$clinique->id : null;
    }

    private function envoyerRelance(Clinique $clinique, RendezVous $rendezVous): bool
    {
        $patient = $rendezVous->patient;
        $client = $patient?->client;
        $ouverte = $this->organisationsOuvertes[$clinique->id] ??= (bool) $clinique->societe?->organisation?->estOuverte();
        if (! $ouverte || $patient === null || $client === null || $patient->rappels_coupes || $patient->decede_le !== null) {
            return false;
        }
        // Un rendez-vous déjà repris pour l'animal (par téléphone, au guichet) : pas de relance
        $repris = RendezVous::withoutGlobalScopes()->where('patient_id', $patient->id)->where('starts_at', '>', now())
            ->whereNotIn('status', [StatutRendezVous::Annule, StatutRendezVous::Noshow])->exists();
        if ($repris) {
            return false;
        }
        $jour = substr($this->momentLocal($rendezVous, $clinique), 0, 10);
        $attributs = ['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => 'noshow',
            'rendez_vous_id' => $rendezVous->id, 'libelle' => 'Relance après une absence', 'echeance_le' => $jour];
        $parti = false;
        if (self::reglages($clinique)['email'] && filled($client->email) && $this->accepteLesRappels($client)) {
            $rappel = $this->prendre(self::cle('noshow', $rendezVous->id, $jour, 'email'), [...$attributs, 'canal' => 'email']);
            if ($rappel !== null) {
                $this->expedier($rappel, $clinique, $client, null, $rendezVous);
                $parti = $rappel->statut === 'envoye';
            }
        }
        $mobile = $this->mobilePourSms($clinique, $client);
        if ($mobile !== null) {
            $rappel = $this->prendre(self::cle('noshow', $rendezVous->id, $jour, 'sms'), [...$attributs, 'canal' => 'sms']);
            if ($rappel !== null) {
                $lien = self::lienReprise($clinique);
                $suite = $lien !== null ? 'Reprenez rendez-vous : '.$lien : (filled($clinique->tel) ? 'Pour reprendre rendez-vous : '.$clinique->tel.'.' : 'Contactez la clinique pour reprendre rendez-vous.');
                $this->expedierSms($rappel, $clinique, $mobile, $clinique->nom.' : '.$patient->nom.' n’a pas pu venir au rendez-vous. '.$suite);
                $parti = $parti || $rappel->statut === 'envoye';
            }
        }

        return $parti;
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
            Mail::to($client->email)->send(match (true) {
                $vaccination !== null => new RappelVaccin($vaccination, $clinique, $client, $lien),
                $rappel->objet === 'noshow' => new RelanceNoShow($rendezVous, $clinique, $client, $lien, self::lienReprise($clinique)),
                default => new RappelControle($rendezVous, $clinique, $client, $lien),
            });
            $rappel->fill(['statut' => 'envoye', 'erreur' => null, 'envoye_le' => now()]);
        } catch (Throwable $e) {
            report($e);
            $rappel->fill(['statut' => 'echec', 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $rappel->save();
    }

    /**
     * Un SMS « Veterinary » : réservé dans le quota du mois, rendu si Twilio
     * le refuse ; quota atteint, il ne part pas (et n'est pas repris).
     */
    private function expedierSms(RappelEnvoye $rappel, Clinique $clinique, string $mobile, string $texte): void
    {
        $organisation = $clinique->societe->organisation;
        $rappel->adresse = $mobile;
        $rappel->tentatives = (int) $rappel->tentatives + 1;
        // Dans l'alphabet SMS (un segment le plus souvent) ; le quota compte les segments facturés
        $texte = TexteSms::versGsm($texte);
        $segments = TexteSms::segments($texte);
        $quota = app(QuotaSms::class);
        $mois = $quota->reserver($organisation, $segments);
        if ($mois === null) {
            $rappel->fill(['statut' => 'echec', 'erreur' => 'Quota de SMS du mois atteint : achetez un pack dans l’abonnement.', 'tentatives' => self::TENTATIVES_MAX]);
            $rappel->save();

            return;
        }
        try {
            Notification::route(TwilioChannel::class, $mobile)->notifyNow(new RappelSms($texte));
            $rappel->fill(['statut' => 'envoye', 'erreur' => null, 'envoye_le' => now()]);
        } catch (Throwable $e) {
            report($e);
            $quota->rendre($organisation, $mois, $segments);
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
     * @param  array{email: bool, sms: bool, sms_disponible: bool, delai_jours: int, vaccins: bool, controles: bool, relance_noshow: bool, messages_recus: bool, messages_reponses_seulement: bool}  $reglages
     * @param  CarbonImmutable  $prochain  le prochain moment où un rappel peut partir
     * @param  CarbonImmutable  $dernier  le dernier moment où ce rappel a encore un sens
     * @return array{objet: string, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, echeance_le: string, envoi_le: string, canaux: list<string>, bloque: ?string}
     */
    private function ligneAVenir(Clinique $clinique, string $objet, string $libelle, Patient $patient, string $echeance, array $reglages, CarbonImmutable $prochain, CarbonImmutable $dernier): array
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
            // Par où il partira (e-mail, SMS), dit à l'écran (28.10.2026)
            'canaux' => $client === null ? [] : array_values(array_filter([
                $reglages['email'] && filled($client->email) && $this->accepteLesRappels($client) ? 'email' : null,
                $this->mobilePourSms($clinique, $client) !== null ? 'sms' : null,
            ])),
            // Ce qui l'empêchera de partir, dit tel quel
            'bloque' => match (true) {
                $patient->decede_le !== null => 'Animal décédé',
                (bool) $patient->rappels_coupes => 'Rappels coupés pour '.$patient->nom,
                // Un SMS peut partir : le rappel n'est pas bloqué (sauf échéance passée)
                $client !== null && $this->mobilePourSms($clinique, $client) !== null => $prochain->greaterThan($dernier) ? 'Échéance passée avant le prochain envoi' : null,
                // Respecter son choix (décision de Jim) : SMS seulement, la clinique n'en envoie pas
                $client !== null && $this->veutLesSms($client) && ! $this->accepteLesRappels($client) && (! $reglages['sms'] || ! QuotaSms::estConfigure()) => 'SMS seulement, que la clinique n’envoie pas',
                // SMS seulement, la clinique en envoie, mais la fiche n'a pas de mobile (comme le carnet)
                $client !== null && $this->veutLesSms($client) && ! $this->accepteLesRappels($client) => 'Pas de numéro de mobile',
                ! $reglages['email'] => 'Rappels par e-mail coupés',
                $client === null || blank($client->email) => 'Pas d’adresse e-mail',
                ! $this->accepteLesRappels($client) => 'Refusés par le propriétaire',
                // Saisi trop tard (après 20 h pour une échéance du jour) : il ne partira plus
                $prochain->greaterThan($dernier) => 'Échéance passée avant le prochain envoi',
                default => null,
            },
        ];
    }
}
