<?php

namespace App\Services\Rappels;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Mail\RappelControle;
use App\Mail\RappelRendezVous;
use App\Mail\RappelVaccin;
use App\Mail\RelanceDevis;
use App\Mail\RelanceNoShow;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\SiteWeb;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\RappelSms;
use App\Services\Clients\OwnerPreferences;
use App\Services\EtatVaccinal;
use App\Services\Messages\QuotaSms;
use App\Support\DocumentHeader;
use App\Support\Telephone;
use App\Support\TexteSms;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 *
 * Ajoutés le 01.11.2026 (écran « Rappels », choix A de Jim), chacun
 * réglable : chaque rendez-vous la veille ; une relance quand un vaccin
 * est en retard (sept jours après l'échéance, une fois) ; une relance d'un
 * devis envoyé en ligne et resté sans réponse (sept jours après, une fois,
 * jamais s'il a déjà été relancé à la main). Un rappel de vaccin envoyé à
 * la main compte : l'automatique ne repart pas pour la même échéance.
 */
class RappelsProprietaires
{
    public const array DELAIS = [7, 3, 1];

    private const int HEURE_DEBUT = 8;

    private const int HEURE_FIN = 20;

    /** La minute de chaque heure où passe `rappels:envoyer` (routes/console.php, hourlyAt(5)) */
    private const int MINUTE_PASSAGE = 5;

    private const int TENTATIVES_MAX = 3;

    /**
     * Après le dernier passage du soir (19 h 05, heure de la clinique) : un
     * rendez-vous pris ou déplacé à ce moment au lendemain n'a pas eu son
     * rappel de la veille (RendezVous::booted, `deplace_le`).
     */
    public static function apresLeDernierPassage(CarbonImmutable $moment, string $fuseau): bool
    {
        $local = $moment->setTimezone($fuseau);

        return $local->gte($local->setTime(self::HEURE_FIN - 1, self::MINUTE_PASSAGE));
    }

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

    /** @var array<int, ?bool> choix SMS de l'espace, null tant qu'il n'a pas choisi */
    private array $smsEspace = [];

    /**
     * Les rendez-vous qui ne se rappellent pas la veille : le contrôle (son
     * propre rappel), l'euthanasie et l'urgence (un message serait déplacé),
     * le créneau tampon (sans animal).
     */
    private const array SANS_RAPPEL_VEILLE = [TypeRendezVous::Controle, TypeRendezVous::Euthanasie, TypeRendezVous::Urgence, TypeRendezVous::Tampon];

    /** Une relance de vaccin en retard, ou de devis sans réponse, part sept jours après */
    public const int JOURS_RELANCE = 7;

    /**
     * Une relance (vaccin en retard, devis sans réponse) part le septième
     * jour, ou dans les deux jours qui suivent si l'envoi a manqué ; jamais
     * plus tard : allumer la relance n'envoie pas d'un coup tout l'arriéré
     * (vérification du 01.11.2026).
     */
    public const int JOURS_RATTRAPAGE = 2;

    /** @return array{email: bool, sms: bool, sms_disponible: bool, delai_jours: int, vaccins: bool, controles: bool, rendez_vous: bool, relance_vaccin_retard: bool, relance_devis: bool, relance_noshow: bool, messages_recus: bool, messages_reponses_seulement: bool} */
    public static function reglages(Clinique $clinique): array
    {
        return [
            // Décision de Jim (28.10.2026) : la clinique ne choisit plus les
            // canaux ; l'e-mail partout, le SMS dès que Twilio est configuré,
            // et seul le client choisit
            'email' => true,
            'sms' => QuotaSms::estConfigure(),
            // Les SMS peuvent partir (clés Twilio en place)
            'sms_disponible' => QuotaSms::estConfigure(),
            'delai_jours' => (int) ($clinique->rappels_delai_jours ?? 7),
            'vaccins' => (bool) ($clinique->rappels_vaccins ?? true),
            'controles' => (bool) ($clinique->rappels_controles ?? true),
            // Écran « Rappels » (01.11.2026)
            'rendez_vous' => (bool) ($clinique->rappels_rendez_vous ?? true),
            'relance_vaccin_retard' => (bool) ($clinique->relance_vaccin_retard ?? true),
            'relance_devis' => (bool) ($clinique->relance_devis ?? true),
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
        foreach ($this->rendezVousDus() as $rendezVous) {
            $partis += $this->envoyerAutomatique($rendezVous->clinique, $rendezVous->patient, 'rdv', null, $rendezVous) ? 1 : 0;
        }
        foreach ($this->vaccinsEnRetard() as [$vaccination, $clinique]) {
            $partis += $this->envoyerAutomatique($clinique, $vaccination->patient, 'vaccin_retard', $vaccination, null) ? 1 : 0;
        }
        foreach ($this->devisSansReponse() as $devis) {
            $partis += $this->relancerDevis($devis) ? 1 : 0;
        }

        return $partis;
    }

    /** Un vaccin déjà renouvelé (injection plus récente du même vaccin, ou d'un combiné qui le couvre) : la règle unique */
    public function estRenouvele(Vaccination $vaccination): bool
    {
        $injections = Vaccination::query()->with('produit.valences')->where('patient_id', $vaccination->patient_id)->get();

        return isset(EtatVaccinal::renouvelees($injections)[$vaccination->id]);
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
            // Déjà renouvelé : « à faire avant le… » serait faux (vérification du 01.11.2026)
            $vaccination->echeance_le !== null && $this->estRenouvele($vaccination) => 'Ce vaccin a déjà été renouvelé : aucun rappel à envoyer.',
            // Les rappels coupés (par lui dans son espace, ou par la clinique sur la fiche) le restent, même pour un envoi à la main (03.11.2026)
            $client !== null && $this->estCoupe($client, 'vaccin') => 'Rappels de vaccins coupés pour ce propriétaire.',
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

    /** Les blocages qui disent que personne ne peut joindre le client, et leur fin de phrase dans « À faire » */
    private const INJOIGNABLE = [
        'Pas d’adresse e-mail' => 'pas d’adresse e-mail',
        'Pas de numéro de mobile' => 'pas de numéro de mobile',
        'SMS seulement, pas encore disponibles' => 'le client ne veut que des SMS, pas encore disponibles',
    ];

    /**
     * Prochains rappels de la clinique (45 jours) : ce qui partira, et quand.
     *
     * @return list<array{objet: string, source_id: ?int, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, telephone: ?string, echeance_le: string, prevu_le: string, envoi_le: string, canaux: list<string>, bloque: ?string, devis_id?: int}>
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
            $aLaMain = $this->rappelesALaMain($vaccins);
            $this->lireLesRefus($vaccins->map(fn (Vaccination $v): ?Client => $v->patient?->client));
            foreach ($vaccins as $vaccination) {
                if (($references[$vaccination->patient_id] ?? null)?->id !== $clinique->id
                    || self::dejaParti($envoyes, 'vaccin', $vaccination->id, $vaccination->echeance_le->toDateString())
                    || isset($aLaMain[$vaccination->id.':'.$vaccination->echeance_le->toDateString()])) {
                    continue;
                }
                $echeance = $vaccination->echeance_le->toDateString();
                // Un vaccin se rappelle jusqu'au jour de son échéance compris
                $dernier = CarbonImmutable::parse($echeance.' 23:59:59', $clinique->fuseau_horaire ?: 'Europe/Zurich');
                $lignes[] = $this->ligneAVenir($clinique, 'vaccin', $vaccination->nom, $vaccination->patient, $echeance, $reglages, $prochain, $dernier, $vaccination->id);
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
                    $lignes[] = $this->ligneAVenir($clinique, 'controle', 'Contrôle', $rendezVous->patient, $jour, $reglages, $prochain, $rendezVous->starts_at->toImmutable(), $rendezVous->id, $rendezVous);
                }
            }
        }

        if ($reglages['rendez_vous']) {
            $rendezVous = RendezVous::withoutGlobalScopes()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
                ->where('clinique_id', $clinique->id)->whereNotIn('type', self::SANS_RAPPEL_VEILLE)->where('status', StatutRendezVous::Prevu)
                ->whereNotNull('patient_id')->whereBetween('starts_at', [now(), $limite->endOfDay()])->get();
            $envoyes = $this->clesEnvoyees($rendezVous->flatMap(fn (RendezVous $r): array => self::cles('rdv', $r->id, $this->momentLocal($r, $clinique))));
            $this->lireLesRefus($rendezVous->map(fn (RendezVous $r): ?Client => $r->patient?->client));
            $reglagesVeille = [...$reglages, 'delai_jours' => 1];
            foreach ($rendezVous as $r) {
                if (! self::dejaParti($envoyes, 'rdv', $r->id, $this->momentLocal($r, $clinique))) {
                    $jour = substr($this->momentLocal($r, $clinique), 0, 10);
                    // La veille : il doit partir avant 20 h le jour d'avant ; pris la veille au soir, le matin même, deux heures avant au plus tard
                    $fuseau = $clinique->fuseau_horaire ?: 'Europe/Zurich';
                    $veille = CarbonImmutable::parse($jour.' '.(self::HEURE_FIN - 1).':59:59', $fuseau)->subDay();
                    $dernier = $this->prisApresLeDernierPassage($r, CarbonImmutable::parse($jour, $fuseau)) ? max($veille, $r->starts_at->toImmutable()->subHours(2)) : $veille;
                    $lignes[] = $this->ligneAVenir($clinique, 'rdv', $r->type === TypeRendezVous::Teleconsultation ? 'Téléconsultation' : 'Rendez-vous', $r->patient, $jour, $reglagesVeille, $prochain, $dernier, $r->id, $r);
                }
            }
        }
        if ($reglages['vaccins'] && $reglages['relance_vaccin_retard']) {
            // Échus depuis peu, encore dus : la relance part sept jours après l'échéance
            $retards = $this->dernieresInjections($aujourdhui->subDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE), $aujourdhui->subDay(), $clinique->societe->organisation_id);
            $references = $this->cliniquesDeReference($retards->pluck('patient'));
            $envoyes = $this->clesEnvoyees($retards->flatMap(fn (Vaccination $v): array => self::cles('vaccin_retard', $v->id, $v->echeance_le->toDateString())));
            $this->lireLesRefus($retards->map(fn (Vaccination $v): ?Client => $v->patient?->client));
            $reglagesRetard = [...$reglages, 'delai_jours' => -self::JOURS_RELANCE];
            $relancesALaMain = $this->relancesALaMain($retards);
            foreach ($retards as $vaccination) {
                if (($references[$vaccination->patient_id] ?? null)?->id !== $clinique->id || self::dejaParti($envoyes, 'vaccin_retard', $vaccination->id, $vaccination->echeance_le->toDateString())
                    || isset($relancesALaMain[$vaccination->id])) {
                    continue;
                }
                $echeance = $vaccination->echeance_le->toDateString();
                $dernier = CarbonImmutable::parse($echeance.' 23:59:59', $clinique->fuseau_horaire ?: 'Europe/Zurich')->addDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE);
                $lignes[] = [...$this->ligneAVenir($clinique, 'vaccin_retard', $vaccination->nom.', en retard', $vaccination->patient, $echeance, $reglagesRetard, $prochain, $dernier, $vaccination->id)];
            }
        }
        if ($reglages['relance_devis']) {
            $devis = Devis::query()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes(), 'patient.client.user'])
                ->where('clinique_id', $clinique->id)->where('statut', StatutDevis::Envoye)->where('canal', CanalDevis::Portail)
                ->whereNull('relance_le')->whereNotNull('patient_id')->whereNotNull('envoye_le')->get()
                ->reject(fn (Devis $d): bool => $d->estExpire());
            $this->lireLesRefus($devis->map(fn (Devis $d): ?Client => $d->patient?->client));
            foreach ($devis as $d) {
                $envoi = CarbonImmutable::parse($d->envoye_le->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString())->addDays(self::JOURS_RELANCE);
                // Au-delà du rattrapage de deux jours, elle ne partira plus (pas de vague au déploiement) : l'équipe le voit dans « À faire »
                if ($envoi->addDays(self::JOURS_RATTRAPAGE)->lt(CarbonImmutable::parse($prochain->toDateString()))) {
                    continue;
                }
                $client = $d->patient?->client;
                $adresse = $client?->user?->email ?: $client?->email;
                $lignes[] = [
                    'objet' => 'devis',
                    'source_id' => $d->id,
                    'libelle' => 'Devis '.$d->numero.' sans réponse',
                    'patient' => ['id' => $d->patient->id, 'nom' => $d->patient->nom],
                    'proprietaire' => $client ? self::nomDe($client) : null,
                    'telephone' => $client?->tel,
                    'echeance_le' => ($d->valide_jusquau ?? $envoi)->toDateString(),
                    'prevu_le' => $envoi->toDateString(),
                    'envoi_le' => max($envoi, CarbonImmutable::parse($prochain->toDateString()))->toDateString(),
                    'canaux' => filled($adresse) ? ['email'] : [],
                    'bloque' => match (true) {
                        $d->patient->decede_le !== null => 'Animal décédé',
                        (bool) $d->patient->rappels_coupes => 'Rappels coupés pour '.$d->patient->nom,
                        $client !== null && $this->estCoupe($client, 'devis') => 'Rappels de devis coupés',
                        $client === null || blank($adresse) => 'Pas d’adresse e-mail',
                        ! $this->accepteLesRappels($client) => 'E-mails de rappel coupés',
                        default => null,
                    },
                    'devis_id' => $d->id,
                ];
            }
        }

        usort($lignes, fn (array $a, array $b): int => [$a['envoi_le'], $a['echeance_le']] <=> [$b['envoi_le'], $b['echeance_le']]);

        return $lignes;
    }

    /**
     * Ce qui attend l'équipe (01.11.2026) : ce qu'aucun message automatique
     * ne règle. Un vaccin en retard depuis quatorze jours ou plus et
     * toujours pas renouvelé ; une demande de renouvellement d'ordonnance ;
     * un devis sans réponse depuis quatorze jours (en ligne, malgré la
     * relance) ou sept jours (papier : il se relance par téléphone).
     *
     * @return list<array<string, mixed>>
     */
    public function aFaire(Clinique $clinique): array
    {
        $aujourdhui = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
        $lignes = [];

        $retards = $this->dernieresInjections($aujourdhui->subDays(180), $aujourdhui->subDays(14), $clinique->societe->organisation_id);
        $references = $this->cliniquesDeReference($retards->pluck('patient'));
        foreach ($retards as $vaccination) {
            if (($references[$vaccination->patient_id] ?? null)?->id !== $clinique->id || $vaccination->patient->rappels_coupes) {
                continue;
            }
            $client = $vaccination->patient->client;
            $lignes[] = [
                'type' => 'vaccin',
                'id' => $vaccination->id,
                'patient' => ['id' => $vaccination->patient->id, 'nom' => $vaccination->patient->nom],
                'proprietaire' => $client ? self::nomDe($client) : null,
                'telephone' => $client?->tel,
                'quoi' => $vaccination->nom.' en retard depuis le '.$vaccination->echeance_le->format('d.m.Y'),
                'depuis_le' => $vaccination->echeance_le->toDateString(),
            ];
        }

        $demandes = Ordonnance::query()
            ->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes(), 'demandesRenouvellement' => fn ($d) => $d->whereNull('traitee_le')])
            ->where('clinique_id', $clinique->id)->where('statut', StatutOrdonnance::Active)
            ->whereHas('demandesRenouvellement', fn ($d) => $d->whereNull('traitee_le'))
            ->get();
        foreach ($demandes as $ordonnance) {
            $client = $ordonnance->patient?->client;
            $le = $ordonnance->demandesRenouvellement->sortBy('created_at')->first()?->created_at;
            $lignes[] = [
                'type' => 'ordonnance',
                'id' => $ordonnance->id,
                'patient' => ['id' => $ordonnance->patient_id, 'nom' => $ordonnance->patient?->nom],
                'proprietaire' => $client ? self::nomDe($client) : null,
                'telephone' => $client?->tel,
                'quoi' => 'Demande le renouvellement de '.$ordonnance->numero.' depuis son espace',
                'depuis_le' => $le?->toDateString(),
            ];
        }

        $devis = Devis::query()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)->where('statut', StatutDevis::Envoye)->whereNotNull('patient_id')->whereNotNull('envoye_le')
            ->get()
            ->reject(fn (Devis $d): bool => $d->estExpire());
        foreach ($devis as $d) {
            $jours = (int) $d->envoye_le->diffInDays(now());
            $seuil = $d->canal === CanalDevis::Papier ? self::JOURS_RELANCE : 2 * self::JOURS_RELANCE;
            if ($jours < $seuil) {
                continue;
            }
            $client = $d->patient?->client;
            $lignes[] = [
                'type' => 'devis',
                'id' => $d->id,
                'patient' => ['id' => $d->patient_id, 'nom' => $d->patient?->nom],
                'proprietaire' => $client ? self::nomDe($client) : null,
                'telephone' => $client?->tel,
                'quoi' => ($d->canal === CanalDevis::Papier ? 'Devis papier ' : 'Devis ').$d->numero.' sans réponse depuis '.$jours.' jours',
                'depuis_le' => $d->envoye_le->toDateString(),
            ];
        }

        // Un rappel qui ne partira pas faute de moyen de joindre le client : l'équipe l'appelle (Jim, 11.11.2026),
        // le jour où il aurait dû partir, pas avant (rien de ce qui n'est « pas encore à faire »). Pas ceux qu'un
        // choix bloque (rappels coupés, animal décédé) : on ne rappelle pas qui n'en veut pas.
        foreach ($this->aVenir($clinique) as $ligne) {
            // Le jour prévu, pas le prochain passage : après 19 h, celui-ci tombe demain (vérification du 11.11.2026)
            if (! isset(self::INJOIGNABLE[$ligne['bloque']]) || $ligne['prevu_le'] > $aujourdhui->toDateString()) {
                continue;
            }
            $lignes[] = [
                'type' => $ligne['objet'] === 'vaccin_retard' ? 'vaccin' : $ligne['objet'],
                'id' => $ligne['objet'].'-'.($ligne['source_id'] ?? $ligne['patient']['id'].'-'.$ligne['echeance_le']),
                'patient' => $ligne['patient'],
                'proprietaire' => $ligne['proprietaire'],
                'telephone' => $ligne['telephone'],
                'quoi' => self::rappelQuiNePartPas($ligne).' ne part pas : '.($ligne['proprietaire'] === null ? 'pas de propriétaire sur la fiche' : self::INJOIGNABLE[$ligne['bloque']]),
                'depuis_le' => $ligne['envoi_le'],
                ...(isset($ligne['devis_id']) ? ['devis_id' => $ligne['devis_id']] : []),
            ];
        }

        usort($lignes, fn (array $a, array $b): int => ($a['depuis_le'] ?? '') <=> ($b['depuis_le'] ?? ''));

        return $lignes;
    }

    /** « Le rappel Rage du 10.11.2026 », « La relance du devis DV-2026-018 » : ce qui ne part pas, en mots */
    private static function rappelQuiNePartPas(array $ligne): string
    {
        $le = CarbonImmutable::parse($ligne['echeance_le'])->format('d.m.Y');

        return match ($ligne['objet']) {
            'vaccin' => 'Le rappel '.$ligne['libelle'].' du '.$le,
            'vaccin_retard' => 'La relance '.str_replace(', en retard', '', $ligne['libelle']).' du '.$le,
            'rdv' => 'Le rappel du rendez-vous du '.$le,
            'controle' => 'Le rappel du contrôle du '.$le,
            'devis' => 'La relance du '.lcfirst(str_replace(' sans réponse', '', $ligne['libelle'])),
            default => 'Le rappel du '.$le,
        };
    }

    /**
     * Le message tel que le client le reçoit (01.11.2026) : sujet, e-mail
     * rendu, SMS, pour un animal d'exemple, avec la clinique elle-même.
     *
     * @return array{sujet: string, html: string, sms: ?string}
     */
    public function apercu(Clinique $clinique, string $objet): array
    {
        $fuseau = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $client = new Client(['prenom' => 'Paul', 'nom' => 'Exemple', 'email' => 'paul.exemple@exemple.ch']);
        $patient = new Patient(['nom' => 'Pilou']);
        $patient->setRelation('client', $client);
        $jour = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
        $vaccination = new Vaccination(['nom' => 'Rage', 'injectee_le' => $jour->subYear(), 'echeance_le' => $objet === 'vaccin_retard' ? $jour->subDays(self::JOURS_RELANCE) : $jour->addDays(self::reglages($clinique)['delai_jours'])]);
        $vaccination->setRelation('patient', $patient);
        $rendezVous = new RendezVous(['starts_at' => CarbonImmutable::parse($jour->addDay()->toDateString().' 10:30', $fuseau)->utc()]);
        $rendezVous->setRelation('patient', $patient);
        $lien = rtrim((string) config('app.frontend_url'), '/');

        $mail = match ($objet) {
            'vaccin', 'vaccin_retard' => new RappelVaccin($vaccination, $clinique, $client, $lien, $objet === 'vaccin_retard'),
            'controle' => new RappelControle($rendezVous, $clinique, $client, $lien),
            'rdv' => new RappelRendezVous($rendezVous, $clinique, $client, $lien),
            'noshow' => new RelanceNoShow($rendezVous, $clinique, $client, $lien, self::lienReprise($clinique)),
            default => (function () use ($clinique, $patient, $jour): RelanceDevis {
                $devis = new Devis(['numero' => 'DV-2026-017', 'valide_jusquau' => $jour->addDays(23)]);
                $devis->setRelation('clinique', $clinique);
                $devis->setRelation('patient', $patient);

                return new RelanceDevis($devis);
            })(),
        };
        $sms = match ($objet) {
            'vaccin', 'vaccin_retard' => self::texteSms($clinique, $patient, $vaccination, null, $objet),
            'controle', 'rdv' => self::texteSms($clinique, $patient, null, $rendezVous, $objet),
            'noshow' => $clinique->nom.' : '.$patient->nom.' n’a pas pu venir au rendez-vous. '.(self::lienReprise($clinique) !== null ? 'Reprenez rendez-vous : '.self::lienReprise($clinique) : (filled($clinique->tel) ? 'Pour reprendre rendez-vous : '.$clinique->tel.'.' : 'Contactez la clinique pour reprendre rendez-vous.')),
            default => null,
        };

        return ['sujet' => (string) $mail->envelope()->subject, 'html' => $mail->render(), 'sms' => $sms === null ? null : TexteSms::versGsm($sms)];
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
        $preferences = PreferencesProprietaire::query()->whereIn('user_id', $users->all())->get(['user_id', 'rappels_email', 'rappels_sms', 'rappels_choisis_le', 'muted_reminders'])->keyBy('user_id');
        foreach ($users as $id) {
            $this->refusEspace[$id] = ($preferences[$id]->rappels_email ?? null) !== false;
            $this->coupesEspace[$id] = array_values(array_intersect(OwnerPreferences::KINDS, (array) ($preferences[$id]->muted_reminders ?? [])));
            $this->smsEspace[$id] = self::choixSms($preferences[$id] ?? null);
        }
    }

    /** @var array<int, list<string>> types de rappels coupés dans l'espace, par compte */
    private array $coupesEspace = [];

    /** Ce type de rappel est-il coupé pour ce propriétaire (son espace avec un compte, sinon la fiche) ? 03.11.2026 */
    private function estCoupe(Client $client, string $objet): bool
    {
        $kind = OwnerPreferences::kindOf($objet);
        if ($kind === null) {
            return false;
        }
        $coupes = $client->user_id !== null
            ? ($this->coupesEspace[$client->user_id] ??= OwnerPreferences::mutedFor($client))
            : OwnerPreferences::mutedFor($client);

        return in_array($kind, $coupes, true);
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
     * s'il a un compte et l'a fait ; sinon la case cochée par la clinique
     * sur sa fiche (décision de Jim, 28.10.2026 : elle vaut encore quand il
     * obtient un compte, tant qu'il n'a pas choisi lui-même).
     */
    public function veutLesSms(Client $client): bool
    {
        if ($client->user_id === null) {
            return (bool) $client->rappels_sms;
        }
        if (! array_key_exists($client->user_id, $this->smsEspace)) {
            $this->smsEspace[$client->user_id] = self::choixSms(PreferencesProprietaire::query()->where('user_id', $client->user_id)->first(['rappels_sms', 'rappels_choisis_le']));
        }

        return $this->smsEspace[$client->user_id] ?? (bool) $client->rappels_sms;
    }

    /** Le choix SMS fait dans l'espace, ou null s'il n'a pas encore choisi (SMS voulus = choisis) */
    public static function choixSms(?PreferencesProprietaire $preferences): ?bool
    {
        if ($preferences === null || (! $preferences->rappels_sms && $preferences->rappels_choisis_le === null)) {
            return null;
        }

        return (bool) $preferences->rappels_sms;
    }

    /** Le mobile où envoyer le SMS, si la clinique l'utilise et que le client le veut ; null sinon. */
    private function mobilePourSms(Clinique $clinique, Client $client): ?string
    {
        if (! QuotaSms::estConfigure() || ! $this->veutLesSms($client)) {
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
                ! QuotaSms::estConfigure() => $nom.' a choisi les SMS seulement, et les SMS ne sont pas encore disponibles.',
                default => 'Pas de numéro de mobile pour '.$nom.'.',
            };
        }

        return blank($client->email) ? 'Pas d’adresse e-mail pour '.$nom.'.' : $nom.' a refusé les rappels par e-mail.';
    }

    /** Le texte du SMS : court (160 caractères), sans détail médical, la clinique et son téléphone. */
    public static function texteSms(Clinique $clinique, Patient $patient, ?Vaccination $vaccination, ?RendezVous $rendezVous, string $objet = ''): string
    {
        $tel = filled($clinique->tel) ? ' Rendez-vous : '.$clinique->tel.'.' : '';
        if ($vaccination !== null) {
            $echeance = ($vaccination->echeance_le ?? $vaccination->injectee_le)->format('d.m.Y');

            return $objet === 'vaccin_retard'
                ? $clinique->nom.' : le vaccin '.$vaccination->nom.' de '.$patient->nom.' était à faire le '.$echeance.'.'.$tel
                : $clinique->nom.' : le vaccin '.$vaccination->nom.' de '.$patient->nom.' est à faire avant le '.$echeance.'.'.$tel;
        }
        $moment = $rendezVous->starts_at->setTimezone($clinique->fuseau_horaire ?: 'Europe/Zurich');
        if ($objet === 'rdv') {
            $quand = $moment->toDateString() === aujourdhui($clinique)->toDateString() ? 'aujourd’hui' : 'demain';
            // Une téléconsultation : pas de déplacement
            $quoi = $rendezVous->type === TypeRendezVous::Teleconsultation ? 'téléconsultation' : 'rendez-vous';

            // Pas de promesse du lien par e-mail : un client aux SMS seuls n'en reçoit pas (vérification du 01.11.2026)
            return $clinique->nom.' : '.$quoi.' de '.$patient->nom.' '.$quand.' à '.$moment->format('H:i').'.'.(filled($clinique->tel) ? ' Un empêchement : '.$clinique->tel.'.' : '');
        }

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
     * Les rendez-vous de demain (sauf les contrôles, rappelés à part), un
     * rappel la veille.
     *
     * @return Collection<int, RendezVous>
     */
    private function rendezVousDus(): Collection
    {
        return RendezVous::withoutGlobalScopes()->with(['patient' => fn ($p) => $p->withoutGlobalScopes(), 'patient.client' => fn ($c) => $c->withoutGlobalScopes()])
            ->whereNotIn('type', self::SANS_RAPPEL_VEILLE)->where('status', StatutRendezVous::Prevu)->whereNotNull('patient_id')
            ->whereBetween('starts_at', [now(), now()->addHours(60)])
            ->get()
            ->filter(function (RendezVous $rendezVous): bool {
                $clinique = $this->clinique($rendezVous->clinique_id);
                if ($clinique === null) {
                    return false;
                }
                $rendezVous->setRelation('clinique', $clinique);
                $aujourdhui = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());
                $jour = substr($this->momentLocal($rendezVous, $clinique), 0, 10);

                return self::reglages($clinique)['rendez_vous'] && $this->enJournee($clinique)
                    && ($jour === $aujourdhui->addDay()->toDateString() || $this->rattrapeLeMatin($rendezVous, $clinique));
            })
            ->values();
    }

    /**
     * Un rendez-vous d'aujourd'hui pris la veille trop tard pour son rappel
     * (après le dernier envoi de 19 h 05) : rappelé le matin même, s'il reste
     * au moins deux heures (vérification du 01.11.2026). Un rendez-vous pris
     * le jour même n'est pas rappelé : le client vient de le prendre.
     */
    private function rattrapeLeMatin(RendezVous $rendezVous, Clinique $clinique): bool
    {
        $fuseau = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $aujourdhui = CarbonImmutable::parse(aujourdhui($clinique)->toDateString(), $fuseau);

        return $rendezVous->starts_at->setTimezone($fuseau)->toDateString() === $aujourdhui->toDateString()
            && $this->prisApresLeDernierPassage($rendezVous, $aujourdhui)
            && $rendezVous->starts_at->gte(now()->addHours(2));
    }

    /**
     * Pris, ou déplacé à cette date (décision de Jim, 01.11.2026), la veille
     * après le dernier passage du soir (19 h 05) et avant minuit : son rappel
     * de la veille n'a pas pu partir. Un rendez-vous pris plus tôt a eu le
     * sien, ou ne l'aurait pas eu non plus (réglage allumé ce jour-là) : pas
     * de vague le matin (vérification du 01.11.2026). L'un ou l'autre suffit :
     * un déplacement ajoute une raison d'être rappelé, il n'en retire jamais.
     */
    private function prisApresLeDernierPassage(RendezVous $rendezVous, CarbonImmutable $jour): bool
    {
        $dernierPassage = $jour->subDay()->setTime(self::HEURE_FIN - 1, self::MINUTE_PASSAGE);

        return collect([$rendezVous->created_at, $rendezVous->deplace_le])
            ->contains(fn ($moment): bool => $moment !== null && $moment->gte($dernierPassage) && $moment->lt($jour));
    }

    /**
     * Les vaccins en retard depuis sept jours ou plus (et au plus trente) et
     * toujours pas renouvelés : une relance, une fois.
     *
     * @return Collection<int, array{0: Vaccination, 1: Clinique}>
     */
    private function vaccinsEnRetard(): Collection
    {
        $aujourdhui = CarbonImmutable::parse(aujourdhui()->toDateString());
        $vaccins = $this->dernieresInjections($aujourdhui->subDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE + 1), $aujourdhui->subDays(self::JOURS_RELANCE - 1), null);
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

                return $reglages['vaccins'] && $reglages['relance_vaccin_retard'] && $this->enJournee($clinique)
                    && $vaccination->echeance_le->toDateString() <= $jour->subDays(self::JOURS_RELANCE)->toDateString()
                    && $vaccination->echeance_le->toDateString() >= $jour->subDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE)->toDateString();
            })
            ->values();
    }

    /**
     * Les devis envoyés en ligne il y a sept jours ou plus, encore sans
     * réponse ni relance, encore valables.
     *
     * @return Collection<int, Devis>
     */
    private function devisSansReponse(): Collection
    {
        return Devis::withoutGlobalScopes()
            ->with(['clinique' => fn ($q) => $q->withoutGlobalScopes(), 'patient' => fn ($q) => $q->withoutGlobalScopes(), 'patient.client' => fn ($q) => $q->withoutGlobalScopes(), 'patient.client.user'])
            ->where('statut', StatutDevis::Envoye)->where('canal', CanalDevis::Portail)
            ->whereNull('relance_le')->whereNotNull('patient_id')
            ->where('envoye_le', '<=', now()->subDays(self::JOURS_RELANCE))
            // Envoyé il y a 7 à 9 jours (jour de la clinique, comme « À venir ») ; la journée exacte se décide par clinique plus bas
            ->where('envoye_le', '>=', now()->subDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE + 1)->startOfDay())
            ->get()
            ->filter(function (Devis $devis): bool {
                $clinique = $this->clinique($devis->clinique_id);

                if ($clinique === null) {
                    return false;
                }
                $fuseau = $clinique->fuseau_horaire ?: 'Europe/Zurich';
                $envoye = $devis->envoye_le->setTimezone($fuseau)->toDateString();
                $jour = CarbonImmutable::parse(aujourdhui($clinique)->toDateString());

                return ! $devis->estExpire() && self::reglages($clinique)['relance_devis'] && $this->enJournee($clinique)
                    && $envoye <= $jour->subDays(self::JOURS_RELANCE)->toDateString()
                    && $envoye >= $jour->subDays(self::JOURS_RELANCE + self::JOURS_RATTRAPAGE)->toDateString();
            })
            ->values();
    }

    /** La relance automatique d'un devis : à l'adresse de son espace, une fois, notée sur le devis */
    private function relancerDevis(Devis $devis): bool
    {
        $clinique = $this->clinique($devis->clinique_id);
        $patient = $devis->patient;
        $client = $patient?->client;
        // Fait pour un propriétaire précédent : jamais relancé chez le nouveau (02.11.2026)
        if (DocumentHeader::isForFormerOwner($devis->recipient, $patient?->client_id)) {
            return false;
        }
        $adresse = $client?->user?->email ?: $client?->email;
        $ouverte = $clinique !== null && ($this->organisationsOuvertes[$clinique->id] ??= (bool) $clinique->societe?->organisation?->estOuverte());
        // Comme tout rappel (vérification du 01.11.2026) : ni animal décédé, ni rappels coupés, ni propriétaire qui les refuse
        if (! $ouverte || $patient === null || $client === null || blank($adresse)
            || $patient->decede_le !== null || $patient->rappels_coupes || ! $this->accepteLesRappels($client)
            || $this->estCoupe($client, 'devis')) {
            return false;
        }
        // Une relance à la main aujourd'hui : pas une seconde automatique (même clé que CycleDevisController)
        if (! Cache::add('devis-relance:'.$devis->id.':'.aujourdhui()->toDateString(), true, now()->addDays(2))) {
            return false;
        }
        $rappel = $this->prendre(self::cle('devis', $devis->id, $devis->envoye_le->toDateString()), [
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => 'devis', 'devis_id' => $devis->id,
            'libelle' => 'Devis '.$devis->numero, 'echeance_le' => ($devis->valide_jusquau ?? $devis->envoye_le)->toDateString(), 'canal' => 'email',
        ]);
        if ($rappel === null) {
            return false;
        }
        $rappel->adresse = $adresse;
        $rappel->tentatives = (int) $rappel->tentatives + 1;
        try {
            Mail::to($adresse)->send(new RelanceDevis($devis->setRelation('clinique', $clinique)));
            $rappel->fill(['statut' => 'envoye', 'erreur' => null, 'envoye_le' => now()]);
            $devis->update(['relance_le' => now()]);
        } catch (Throwable $e) {
            report($e);
            Cache::forget('devis-relance:'.$devis->id.':'.aujourdhui()->toDateString());
            $rappel->fill(['statut' => 'echec', 'erreur' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $rappel->save();

        return $rappel->statut === 'envoye';
    }

    /**
     * Les vaccins déjà rappelés à la main pour leur échéance : l'automatique
     * ne repart pas (vérification du 01.11.2026).
     *
     * @param  Collection<int, Vaccination>  $vaccins
     * @return array<string, true> « id:échéance »
     */
    private function rappelesALaMain(Collection $vaccins): array
    {
        if ($vaccins->isEmpty()) {
            return [];
        }

        $cles = RappelEnvoye::withoutGlobalScopes()->where('manuel', true)->where('statut', 'envoye')
            ->whereIn('vaccination_id', $vaccins->pluck('id')->all())
            ->get(['vaccination_id', 'echeance_le'])
            ->map(fn (RappelEnvoye $r): string => $r->vaccination_id.':'.$r->echeance_le->toDateString())
            ->all();

        return array_fill_keys($cles, true);
    }

    /**
     * Les vaccins rappelés à la main le jour de leur échéance ou après : la
     * relance « en retard » automatique ne repart pas.
     *
     * @param  Collection<int, Vaccination>  $vaccins
     * @return array<int, true>
     */
    private function relancesALaMain(Collection $vaccins): array
    {
        if ($vaccins->isEmpty()) {
            return [];
        }
        $echeances = $vaccins->mapWithKeys(fn (Vaccination $v): array => [$v->id => $v->echeance_le->toDateString()]);
        $ids = RappelEnvoye::withoutGlobalScopes()->where('manuel', true)->where('statut', 'envoye')
            ->whereIn('vaccination_id', $vaccins->pluck('id')->all())->get(['vaccination_id', 'envoye_le'])
            ->filter(fn (RappelEnvoye $r): bool => $r->envoye_le !== null && $r->envoye_le->copy()->setTimezone('Europe/Zurich')->toDateString() >= ($echeances[$r->vaccination_id] ?? '9999'))
            ->pluck('vaccination_id')->unique()->all();

        return array_fill_keys($ids, true);
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
        // La règle unique du renouvellement (EtatVaccinal::renouvelees), animal par animal
        $renouvelees = [];
        foreach ($parAnimal as $injections) {
            $renouvelees += EtatVaccinal::renouvelees($injections);
        }

        return $candidates->filter(fn (Vaccination $vaccination): bool => ! isset($renouvelees[$vaccination->id]))->values();
    }

    private function envoyerAutomatique(Clinique $clinique, ?Patient $patient, string $objet, ?Vaccination $vaccination, ?RendezVous $rendezVous): bool
    {
        $client = self::destinataire($patient, $rendezVous);
        $reglages = self::reglages($clinique);
        // Une organisation fermée (abonnement terminé, vérification refusée) n'envoie plus
        $ouverte = $this->organisationsOuvertes[$clinique->id] ??= (bool) $clinique->societe?->organisation?->estOuverte();
        if (! $ouverte || $patient === null || $client === null || $patient->rappels_coupes || $patient->decede_le !== null) {
            return false;
        }
        // Ce type de rappel coupé par le propriétaire (ou la clinique, sans compte), 03.11.2026
        if ($this->estCoupe($client, $objet)) {
            return false;
        }

        $echeance = $vaccination !== null ? $vaccination->echeance_le->toDateString() : $this->momentLocal($rendezVous, $clinique);
        // Déjà rappelé à la main pour cette échéance : l'automatique ne repart pas
        if ($objet === 'vaccin' && isset($this->rappelesALaMain(collect([$vaccination]))[$vaccination->id.':'.$echeance])) {
            return false;
        }
        // Rappelé à la main après l'échéance : c'était déjà la relance (vérification du 01.11.2026)
        if ($objet === 'vaccin_retard' && isset($this->relancesALaMain(collect([$vaccination]))[$vaccination->id])) {
            return false;
        }
        $id = $vaccination->id ?? $rendezVous->id;
        $attributs = [
            'clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $client->id, 'objet' => $objet,
            'vaccination_id' => $vaccination?->id, 'rendez_vous_id' => $rendezVous?->id,
            'libelle' => match ($objet) {
                'controle' => 'Contrôle',
                'rdv' => 'Rendez-vous',
                default => $vaccination->nom ?? 'Rappel',
            },
            'echeance_le' => substr($echeance, 0, 10),
        ];
        $parti = false;

        // Chaque canal choisi par le client et permis par la clinique (28.10.2026)
        if (filled($client->email) && $this->accepteLesRappels($client)) {
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
                $this->expedierSms($rappel, $clinique, $mobile, self::texteSms($clinique, $patient, $vaccination, $rendezVous, $objet));
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
        $client = self::destinataire($patient, $rendezVous);
        $ouverte = $this->organisationsOuvertes[$clinique->id] ??= (bool) $clinique->societe?->organisation?->estOuverte();
        if (! $ouverte || $patient === null || $client === null || $patient->rappels_coupes || $patient->decede_le !== null) {
            return false;
        }
        if ($this->estCoupe($client, 'noshow')) {
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
                $vaccination !== null => new RappelVaccin($vaccination, $clinique, $client, $lien, $rappel->objet === 'vaccin_retard'),
                $rappel->objet === 'noshow' => new RelanceNoShow($rendezVous, $clinique, $client, $lien, self::lienReprise($clinique)),
                $rappel->objet === 'rdv' => new RappelRendezVous($rendezVous, $clinique, $client, $lien),
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
        // Le prochain passage du planificateur (rappels:envoyer, chaque heure à hh:05), de 8 h à 20 h (vérification du 01.11.2026)
        $maintenant = CarbonImmutable::now($clinique->fuseau_horaire ?: 'Europe/Zurich')->startOfMinute();
        $passage = $maintenant->minute <= self::MINUTE_PASSAGE ? $maintenant->setTime($maintenant->hour, self::MINUTE_PASSAGE) : $maintenant->addHour()->setTime($maintenant->addHour()->hour, self::MINUTE_PASSAGE);

        return match (true) {
            $passage->hour < self::HEURE_DEBUT => $passage->setTime(self::HEURE_DEBUT, self::MINUTE_PASSAGE),
            $passage->hour >= self::HEURE_FIN => $passage->addDay()->setTime(self::HEURE_DEBUT, self::MINUTE_PASSAGE),
            default => $passage,
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
     * @return array{objet: string, source_id: ?int, libelle: string, patient: array{id: int, nom: string}, proprietaire: ?string, telephone: ?string, echeance_le: string, prevu_le: string, envoi_le: string, canaux: list<string>, bloque: ?string}
     */
    private function ligneAVenir(Clinique $clinique, string $objet, string $libelle, Patient $patient, string $echeance, array $reglages, CarbonImmutable $prochain, CarbonImmutable $dernier, ?int $sourceId = null, ?RendezVous $rendezVous = null): array
    {
        // Le client à qui le rappel partirait : pour un rendez-vous, celui qui l'a pris (comme à l'envoi)
        $client = self::destinataire($patient, $rendezVous);
        $envoi = CarbonImmutable::parse($echeance)->subDays($reglages['delai_jours']);
        $aujourdhui = CarbonImmutable::parse($prochain->toDateString());

        return [
            'objet' => $objet,
            // Le vaccin, le rendez-vous : deux rappels du même animal le même jour restent deux lignes
            'source_id' => $sourceId,
            'libelle' => $libelle,
            'patient' => ['id' => $patient->id, 'nom' => $patient->nom],
            'proprietaire' => $client ? self::nomDe($client) : null,
            // Pour l'appeler, si le rappel ne peut pas partir (« À faire », 11.11.2026)
            'telephone' => $client?->tel,
            'echeance_le' => $echeance,
            // Le jour prévu, avant d'être reporté au prochain passage (le soir, ce serait demain)
            'prevu_le' => $envoi->toDateString(),
            'envoi_le' => max($envoi, $aujourdhui)->toDateString(),
            // Par où il partira (e-mail, SMS), dit à l'écran (28.10.2026)
            'canaux' => $client === null ? [] : array_values(array_filter([
                filled($client->email) && $this->accepteLesRappels($client) ? 'email' : null,
                $this->mobilePourSms($clinique, $client) !== null ? 'sms' : null,
            ])),
            // Ce qui l'empêchera de partir, dit tel quel
            'bloque' => match (true) {
                $patient->decede_le !== null => 'Animal décédé',
                (bool) $patient->rappels_coupes => 'Rappels coupés pour '.$patient->nom,
                $client !== null && $this->estCoupe($client, $objet) => 'Rappels de '.OwnerPreferences::LABELS[OwnerPreferences::kindOf($objet)].' coupés',
                // Un SMS peut partir : le rappel n'est pas bloqué (sauf échéance passée)
                $client !== null && $this->mobilePourSms($clinique, $client) !== null => $prochain->greaterThan($dernier) ? 'Échéance passée avant le prochain envoi' : null,
                // Respecter son choix (décision de Jim) : SMS seulement, la clinique n'en envoie pas
                $client !== null && $this->veutLesSms($client) && ! $this->accepteLesRappels($client) && ! QuotaSms::estConfigure() => 'SMS seulement, pas encore disponibles',
                // SMS seulement, la clinique en envoie, mais la fiche n'a pas de mobile (comme le carnet)
                $client !== null && $this->veutLesSms($client) && ! $this->accepteLesRappels($client) => 'Pas de numéro de mobile',
                $client === null || blank($client->email) => 'Pas d’adresse e-mail',
                ! $this->accepteLesRappels($client) => 'E-mails de rappel coupés',
                // Saisi trop tard (après 20 h pour une échéance du jour) : il ne partira plus
                $prochain->greaterThan($dernier) => 'Échéance passée avant le prochain envoi',
                default => null,
            },
        ];
    }

    /**
     * À qui écrire : pour un rendez-vous, la fiche qui l'a pris (l'animal a
     * pu changer de propriétaire depuis, décision de Jim du 03.11.2026) ;
     * sinon la fiche de l'animal.
     */
    private static function destinataire(?Patient $patient, ?RendezVous $rendezVous): ?Client
    {
        if ($rendezVous?->client_id !== null && $rendezVous->client_id !== $patient?->client_id) {
            return Client::withoutGlobalScopes()->find($rendezVous->client_id);
        }

        return $patient?->client;
    }
}
