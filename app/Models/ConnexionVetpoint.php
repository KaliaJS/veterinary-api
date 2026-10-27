<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * La clé Vetpoint d'une clinique (27.10.2026) : chiffrée, jamais sérialisée,
 * lue dans la clinique du jeton seulement (scope `clinique`).
 */
class ConnexionVetpoint extends Model
{
    use AppartientAUneClinique;

    protected $table = 'connexions_vetpoint';

    protected $fillable = ['clinique_id', 'cle', 'verifiee_le'];

    protected $hidden = ['cle'];

    protected function casts(): array
    {
        return ['cle' => 'encrypted', 'verifiee_le' => 'datetime'];
    }

    /**
     * La clé en clair, ou null si elle est illisible (clé de chiffrement du
     * serveur changée sans `APP_PREVIOUS_KEYS`) : la clinique la recolle.
     */
    public function cleEnClair(): ?string
    {
        try {
            return Crypt::decryptString((string) $this->getRawOriginal('cle'));
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
