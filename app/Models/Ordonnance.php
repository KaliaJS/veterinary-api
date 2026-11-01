<?php

namespace App\Models;

use App\Enums\StatutOrdonnance;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\OrdonnanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * L'ordonnance suit le patient — NIVEAU ORGANISATION — et garde la
 * clinique de l'acte pour la sortie de stock. Jamais modifiée après
 * signature : le renouvellement crée une nouvelle ordonnance et clôt la
 * précédente ; la suppression n'existe que dans la fenêtre d'annulation
 * d'une signature (le stock est alors restauré).
 */
class Ordonnance extends Model
{
    /** @use HasFactory<OrdonnanceFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'numero', 'patient_id', 'employe_id',
        'signee_le', 'renouvellements', 'statut', 'note', 'ordonnance_precedente_id',
    ];

    /**
     * Chaque ordonnance reçoit à sa création son code de vérification :
     * quatre chiffres tirés au hasard (01.11.2026, choix de Jim), uniques
     * avec son numéro. Il ne sert qu'avec le numéro, et les essais faux sont
     * comptés par numéro (VerificationOrdonnanceController).
     */
    protected static function booted(): void
    {
        static::creating(function (Ordonnance $ordonnance): void {
            $ordonnance->code_verification ??= self::nouveauCodeVerification((string) $ordonnance->numero);
            // Le code QR porte un jeton long et secret : il ne se devine pas
            $ordonnance->jeton_verification ??= Str::random(32);
        });
    }

    public static function nouveauCodeVerification(string $numero): string
    {
        do {
            $code = str_pad((string) random_int(0, 9_999), 4, '0', STR_PAD_LEFT);
        } while (self::withoutGlobalScopes()->where('numero', $numero)->where('code_verification', $code)->exists());

        return $code;
    }

    /** L'adresse de la page de vérification, sans le protocole (imprimée sous le code QR) */
    public static function adresseVerification(): string
    {
        return preg_replace('#^https?://#', '', rtrim((string) config('app.frontend_url'), '/')).'/ordo';
    }

    protected function casts(): array
    {
        return [
            'signee_le' => 'date',
            'renouvellements' => 'integer',
            'statut' => StatutOrdonnance::class,
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    /** @return HasMany<OrdonnanceLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(OrdonnanceLigne::class);
    }

    /**
     * Demandes de renouvellement déposées par le propriétaire depuis
     * son espace (en attente tant que `traitee_le` est nul).
     *
     * @return HasMany<DemandeRenouvellementOrdonnance, $this>
     */
    public function demandesRenouvellement(): HasMany
    {
        return $this->hasMany(DemandeRenouvellementOrdonnance::class);
    }

    /**
     * Ce qu'il faut pour imprimer l'ordonnance (01.11.2026, format A5) : le
     * signataire et son GLN, l'animal, son détenteur.
     *
     * @return array<int|string, mixed>
     */
    public static function relationsDocument(): array
    {
        return [
            'lignes.produit',
            'clinique.societe',
            'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'employe.user',
            'patient.client',
            'patient.dernierePesee',
            // La demande de renouvellement du propriétaire, à montrer à la clinique (01.11.2026)
            'demandesRenouvellement',
        ];
    }

    /**
     * L'ordonnance telle qu'elle s'imprime, la même pour la clinique et pour
     * l'espace du propriétaire : en-tête de la clinique et lieu, prescripteur
     * (son GLN seulement vérifié auprès de MedReg, c'est lui que contrôle le
     * pharmacien), l'animal (espèce, sexe, dernier poids, puce), le détenteur,
     * les médicaments avec la quantité à délivrer. `$avecDetenteur` : faux
     * pour qui gère un animal réclamé par puce au nom d'une autre fiche.
     *
     * @return array<string, mixed>
     */
    public function document(bool $avecDetenteur = true): array
    {
        $patient = $this->patient;
        $client = $avecDetenteur ? $patient?->client : null;
        $utilisateur = $this->employe?->user;
        $pesee = $patient?->dernierePesee;

        return [
            'numero' => $this->numero,
            'signee_le' => $this->signee_le->toDateString(),
            'statut' => $this->statut->value,
            'lieu' => $this->clinique?->ville,
            'en_tete' => $this->clinique?->enTeteDocument(),
            'prescripteur' => [
                'nom' => $this->employe?->nom,
                'titre' => $this->employe?->role_libelle,
                'gln' => $utilisateur?->gln_verifie_le !== null ? $utilisateur->gln : null,
            ],
            'animal' => $patient === null ? null : [
                'nom' => $patient->nom,
                'espece' => $patient->espece->libelle(),
                'sexe' => $patient->sexe->value,
                'est_sterilise' => (bool) $patient->est_sterilise,
                'poids_kg' => $pesee ? (float) $pesee->poids_kg : null,
                'puce' => $patient->puce,
            ],
            'detenteur' => $client === null ? null : [
                'nom' => $client->nom_affiche,
                'adresse' => implode(', ', array_filter([$client->rue, trim(($client->npa ?? '').' '.($client->localite ?? ''))])),
            ],
            'renouvellements' => $this->renouvellements,
            'note' => $this->note,
            // Vérifier l'authenticité : le code QR mène à la page, l'adresse et le code se tapent aussi
            'verification' => [
                'adresse' => self::adresseVerification(),
                'code' => $this->code_verification,
                'lien' => rtrim((string) config('app.frontend_url'), '/').'/ordo?'.http_build_query(['t' => $this->jeton_verification]),
            ],
            'lignes' => $this->lignes->map(fn (OrdonnanceLigne $ligne): array => [
                'produit' => $ligne->produit?->nom,
                'quantite' => $ligne->quantite,
                'posologie' => $ligne->posologie,
                'duree' => $ligne->duree,
            ])->values()->all(),
        ];
    }

    /** Ordonnance que ce renouvellement a clôturée.
     * @return BelongsTo<Ordonnance, $this> */
    public function precedente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ordonnance_precedente_id');
    }
}
