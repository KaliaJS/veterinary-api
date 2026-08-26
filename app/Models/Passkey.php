<?php

namespace App\Models;

use App\Casts\CredentialRecordCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passkey extends Model
{
    protected $fillable = ['user_id', 'nom', 'credential_id', 'donnees', 'dernier_usage_le'];

    protected function casts(): array
    {
        return [
            'donnees' => CredentialRecordCast::class,
            'dernier_usage_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
