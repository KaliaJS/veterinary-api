<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Terminal de paiement (lecteur Stripe Terminal « smart » : WisePOS E,
 * S700) appairé à une clinique. Le serveur lui envoie les paiements ;
 * aucune app ni SDK côté navigateur.
 */
class TerminalPaiement extends Model
{
    use AppartientAUneClinique;

    protected $table = 'terminaux_paiement';

    protected $fillable = ['clinique_id', 'stripe_reader_id', 'libelle', 'modele', 'numero_serie'];

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
