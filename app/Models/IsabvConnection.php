<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Comment une clinique déclare ses antibiotiques à l'OSAV (08.11.2026) :
 * variante 1, par Veterinary avec son token (chiffré, jamais sérialisé) ;
 * variante 2, dans l'application web de l'OSAV (rien n'est envoyé).
 */
class IsabvConnection extends Model
{
    use AppartientAUneClinique;

    public const VIA_VETERINARY = 1;

    public const VIA_OSAV_WEB = 2;

    protected $fillable = ['clinique_id', 'variant', 'appendix', 'token', 'verified_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['variant' => 'integer', 'verified_at' => 'datetime'];
    }

    /** Le token en clair, ou null s'il manque ou est illisible (clé du serveur changée) : la clinique le recolle */
    public function plainToken(): ?string
    {
        $raw = $this->getRawOriginal('token');
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return Crypt::decryptString((string) $raw);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Veterinary déclare pour elle : variante 1 et un token accepté par l'OSAV */
    public function sendsThroughVeterinary(): bool
    {
        return $this->variant === self::VIA_VETERINARY && $this->verified_at !== null && $this->plainToken() !== null;
    }

    /** La connexion d'une clinique qui déclare par Veterinary, ou null */
    public static function activeFor(int $cliniqueId): ?self
    {
        $connection = self::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->first();

        return $connection?->variant === self::VIA_VETERINARY ? $connection : null;
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
