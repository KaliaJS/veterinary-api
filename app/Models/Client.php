<?php

namespace App\Models;

use App\Enums\Civilite;
use App\Enums\StatutRendezVous;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Services\Patients\OneAccountPerPet;
use App\Support\Telephone;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read float|string|null $solde_du Agrégat de la liste et de la fiche : le reste à payer de ses factures dues (ClientController, 03.11.2026)
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = [
        'organisation_id', 'user_id', 'civilite', 'prenom', 'nom',
        'tel', 'email', 'rue', 'npa', 'localite', 'rappels_sms',
        // Fenêtre propriétaire (03.11.2026)
        'rappels_email', 'emergency_contact', 'emergency_phone', 'note', 'muted_reminders', 'language', 'accepts_news', 'photo_consent_at',
    ];

    /** Un animal, un seul compte (02.11.2026) : la fiche ne prend pas un compte autre que celui de ses animaux */
    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->isDirty('user_id')) {
                OneAccountPerPet::assertClient($client);
            }
            // Une invitation dans l'espace ne survit ni à un autre e-mail, ni à
            // un compte rattaché ou retiré, par n'importe quel chemin (03.11.2026)
            $emailChanged = $client->isDirty('email')
                && mb_strtolower(trim((string) $client->email)) !== mb_strtolower(trim((string) $client->getOriginal('email')));
            if ($client->exists && ($emailChanged || $client->isDirty('user_id')) && ! $client->isDirty('space_invitation_token_hash')) {
                $client->forgetSpaceInvitation();
            }
            // Le même numéro, quelle que soit son écriture (03.11.2026)
            if ($client->isDirty('tel') || ($client->tel !== null && $client->phone_e164 === null)) {
                $client->phone_e164 = Telephone::international($client->tel);
            }
        });
        // Chaque enregistrement qui change la fiche augmente sa version (03.11.2026)
        static::updating(function (Client $client): void {
            $client->version = (int) $client->getOriginal('version') + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'civilite' => Civilite::class,
            'rappels_email' => 'boolean',
            'rappels_sms' => 'boolean',
            'muted_reminders' => 'array',
            'accepts_news' => 'boolean',
            'photo_consent_at' => 'datetime',
            'space_invitation_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** Compte espace client (0..1) — conditionne le canal des devis.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * Animaux du foyer par nom, pour la liste des propriétaires (qui
     * n'affiche que les noms) — la fiche charge `patients` en entier.
     *
     * @return HasMany<Patient, $this>
     */
    public function patientsParNom(): HasMany
    {
        return $this->hasMany(Patient::class)->orderBy('nom')->orderBy('id');
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }

    /**
     * « Mme Lefèvre » — dénormalisation affichée par le planning.
     *
     * @return Attribute<string, never>
     */
    protected function nomAffiche(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->civilite->value ?? '').' '.$this->nom));
    }

    /**
     * La fiche de l'organisation qui a déjà ce mobile (un fixe se partage en
     * famille, un mobile non). Le scope organisation borne la recherche.
     */
    public static function withMobile(?string $number, ?int $except = null): ?self
    {
        if (Telephone::mobile($number) === null) {
            return null;
        }

        return self::query()
            ->where('phone_e164', Telephone::international($number))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('id')
            ->first();
    }

    /** L'invitation en attente effacée : son lien ne vaut plus rien. */
    public function forgetSpaceInvitation(): void
    {
        $this->space_invitation_email = null;
        $this->space_invitation_sent_at = null;
        $this->space_invitation_token_hash = null;
        $this->space_invitation_clinic_id = null;
    }

    /** « Mme Claire Borel » : civilité, prénom et nom. */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->civilite?->value, $this->prenom === '—' ? null : $this->prenom, $this->nom])));
    }

    /** Compteur no-show dérivé des RDV (badge « NO-SHOW ×N »). */
    public function nombreNoShows(): int
    {
        return $this->rendezVous()->where('status', StatutRendezVous::Noshow)->count();
    }
}
