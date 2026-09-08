<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Réglages de l'espace client d'un compte (défauts du proto). */
class PreferencesProprietaire extends Model
{
    protected $table = 'preferences_proprietaire';

    public const array LANGUES = ['fr', 'de', 'it', 'en'];

    protected $fillable = [
        'user_id', 'rappels_email', 'rappels_sms', 'reponses_clinique',
        'nouveautes_boutique', 'statistiques_anonymes', 'langue', 'notifications_lues',
    ];

    protected $attributes = [
        'rappels_email' => true,
        'rappels_sms' => false,
        'reponses_clinique' => true,
        'nouveautes_boutique' => false,
        'statistiques_anonymes' => true,
        'langue' => 'fr',
    ];

    protected function casts(): array
    {
        return [
            'rappels_email' => 'boolean',
            'rappels_sms' => 'boolean',
            'reponses_clinique' => 'boolean',
            'nouveautes_boutique' => 'boolean',
            'statistiques_anonymes' => 'boolean',
            'notifications_lues' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function pour(User $user): self
    {
        return self::firstOrCreate(['user_id' => $user->id]);
    }
}
