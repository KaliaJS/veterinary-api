<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Rattachements clinique de l'utilisateur — un par clinique où il
     * est employé. Le jeton d'API est émis pour l'un d'entre eux.
     *
     * @return HasMany<Employe, $this>
     */
    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class);
    }

    /** @return HasMany<Passkey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** Prénom dérivé de `name` — contrat du front (`user.first`). */
    public function prenom(): string
    {
        return Str::before($this->name, ' ');
    }

    /** Nom dérivé de `name` — contrat du front (`user.last`). */
    public function nomFamille(): string
    {
        return Str::contains($this->name, ' ') ? Str::after($this->name, ' ') : '';
    }
}
