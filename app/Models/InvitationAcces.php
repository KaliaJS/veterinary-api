<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une adresse invitée à créer son compte tant que l'accès est sur
 * invitation (27.10.2026). Adresse rangée en minuscules ; `utilisee_le` :
 * le compte a été créé.
 */
class InvitationAcces extends Model
{
    protected $table = 'invitations_acces';

    protected $fillable = ['email', 'note', 'invitee_par'];

    protected function casts(): array
    {
        return ['utilisee_le' => 'datetime'];
    }

    protected function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = mb_strtolower(trim($email));
    }

    /** @return BelongsTo<User, $this> */
    public function invitant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_par');
    }
}
