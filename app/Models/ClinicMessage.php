<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message entre deux cliniques (01.11.2026) : lu par toute l'équipe de
 * chacune. Pas de scope global : toujours lu avec un filtre explicite sur la
 * clinique du contexte (InternalMessaging).
 */
class ClinicMessage extends Model
{
    protected $fillable = ['sender_clinic_id', 'recipient_clinic_id', 'author_employee_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employe, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'author_employee_id')->withoutGlobalScopes();
    }
}
