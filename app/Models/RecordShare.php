<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un partage du dossier médical (09.11.2026) : un lien provisoire vers les
 * pièces choisies (`link`), ou un export en PDF fait par la clinique
 * (`export`). Le jeton n'est gardé que haché ; le lien vaut jusqu'à
 * `expires_at`, sauf s'il est coupé. Par clinique ; la page publique le lit
 * par son jeton, hors contexte.
 */
class RecordShare extends Model
{
    use AppartientAUneClinique;

    protected $table = 'record_shares';

    /** Durées proposées, en jours (30 par défaut) */
    public const DURATIONS = [7, 30, 90];

    /** Téléchargements complets permis par lien */
    public const MAX_DOWNLOADS_PER_LINK = 5;

    /** Téléchargements complets permis par clinique et par mois, par l'ensemble de ses liens */
    public const MAX_DOWNLOADS_PER_CLINIC_MONTH = 50;

    /**
     * Ouvertures de pièces ou de fichiers permises par lien (choix de Jim,
     * 09.11.2026) : au moins 300, et 8 lectures complètes d'un gros dossier
     */
    public const MIN_OPENINGS = 300;

    public const OPENINGS_READINGS = 8;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'patient_id', 'kind', 'token_hash', 'recipient_email', 'items', 'include_visit_notes',
        'period_from', 'period_to', 'expires_at', 'created_by_id', 'created_by_name', 'revoked_at', 'revoked_by_name', 'full_downloads', 'openings', 'openings_max',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'include_visit_notes' => 'boolean',
            'period_from' => 'date',
            'period_to' => 'date',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'full_downloads' => 'integer',
            'openings' => 'integer',
            'openings_max' => 'integer',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Le fuseau de la clinique qui partage */
    public function timezone(): string
    {
        return Clinique::withoutGlobalScopes()->whereKey($this->clinique_id)->value('fuseau_horaire') ?: 'Europe/Zurich';
    }

    /** Le jour de fin du lien, à l'heure de la clinique : « 9 décembre 2026 » */
    public function expiryDay(): string
    {
        return $this->expires_at->copy()->setTimezone($this->timezone())->locale('fr_CH')->isoFormat('D MMMM YYYY');
    }

    /** La pièce fait partie du partage */
    public function includes(string $type, int $id): bool
    {
        foreach ($this->items ?? [] as $item) {
            if (($item['type'] ?? null) === $type && (int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class)->withoutGlobalScopes();
    }

    /** @return HasMany<RecordShareEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RecordShareEvent::class)->orderBy('at');
    }
}
