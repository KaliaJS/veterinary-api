<?php

namespace Database\Seeders\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Chaque employé de démonstration a son compte, comme dans l'application
 * (seuls les clients peuvent ne pas en avoir). Retrouvé par son e-mail :
 * rejouer les données ne le crée pas deux fois. Connexion par code reçu
 * par e-mail, comme tout compte (le mot de passe n'est jamais utilisé).
 */
trait ComptesDeDemo
{
    protected function compteDeDemo(string $nom, string $email): int
    {
        return User::firstOrCreate(['email' => Str::lower($email)], [
            'name' => trim(preg_replace('/^Dr\.?\s+/u', '', $nom)),
            'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(),
        ])->id;
    }
}
