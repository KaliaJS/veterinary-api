<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message entre deux collègues de la même organisation (01.11.2026) : borné à
 * l'organisation du jeton (scope), et toujours lu avec un filtre explicite
 * sur l'employé du contexte (InternalMessaging).
 */
class StaffMessage extends Model
{
    use AppartientAUneOrganisation;

    protected $fillable = ['organisation_id', 'sender_employee_id', 'recipient_employee_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'sender_employee_id')->withoutGlobalScopes();
    }
}
