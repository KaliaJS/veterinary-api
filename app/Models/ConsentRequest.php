<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un consentement envoyé pour signature (02.11.2026) : le lien personnel,
 * valable 24 heures, par SMS, par e-mail ou dans l'espace du propriétaire.
 * Le jeton en clair n'existe qu'au moment de l'envoi.
 */
class ConsentRequest extends Model
{
    use AppartientAUneClinique;

    public const int VALID_HOURS = 24;

    /** Ce que le propriétaire reconnaît en signant (le texte de l'écran de la clinique, à la première personne) */
    public const string TEXT = 'J’ai reçu les explications sur la nature de l’intervention, les risques de l’anesthésie et le devis, et je donne mon accord.';

    protected $fillable = [
        'clinique_id', 'intervention_id', 'token_hash', 'channels', 'phone', 'email',
        'expires_at', 'signed_at', 'signed_ip', 'signed_user_agent', 'cancelled_at', 'sent_by_employee_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Un nouveau jeton (48 caractères), dont seule l'empreinte est gardée. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** Par le jeton du lien, hors contexte de clinique (la page publique). */
    public static function byToken(string $token): ?self
    {
        return self::withoutGlobalScopes()->where('token_hash', self::hashOf($token))->first();
    }

    public function isOpen(): bool
    {
        return $this->signed_at === null && $this->cancelled_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('signed_at')->whereNull('cancelled_at')->where('expires_at', '>', now());
    }

    /**
     * Ce que la page de signature montre, par le lien comme dans l'espace.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $intervention = $this->intervention;
        $clinic = $intervention->clinique;

        return [
            'clinic' => ['nom' => $clinic->nom, 'tel' => $clinic->tel],
            'patient' => ['nom' => $intervention->patient->nom],
            'owner' => $intervention->patient->client->nom_affiche,
            'procedure' => $intervention->procedure,
            'planned_at' => $intervention->prevu_le,
            'text' => self::TEXT,
            'expires_at' => $this->expires_at,
        ];
    }

    public function link(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/consentement/'.$token;
    }
}
