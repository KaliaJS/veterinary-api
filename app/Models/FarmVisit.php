<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une visite d'exploitation (09.11.2026) : le jour, le vétérinaire, les
 * groupes vus, les constats ; facturée à l'exploitation.
 */
class FarmVisit extends Model
{
    use AppartientAUneOrganisation;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'client_id', 'visited_on', 'vet_user_id', 'vet_name', 'herd_ids', 'findings', 'facture_id',
        // Une visite de la convention Médvét : les quatre points de l'annexe 1 ch. 1
        'medvet_agreement_id', 'health_status', 'problems', 'indications', 'medicine_use',
    ];

    protected function casts(): array
    {
        return ['visited_on' => 'date:Y-m-d', 'herd_ids' => 'array'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
