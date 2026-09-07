<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Compte créé par inscription publique (passkey ou code e-mail) : sans
 * rattachement clinique — la session est « sans clinique » tant qu'une
 * organisation ne l'embauche pas (POST /equipe/employes rattache un
 * compte existant par son e-mail). Le nom est provisoire (partie locale
 * de l'e-mail) ; le mot de passe, jamais utilisé, est un aléa.
 */
class CreationCompte
{
    public function creer(string $email, bool $emailVerifie = false): User
    {
        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(40),
            'email_verified_at' => $emailVerifie ? now() : null,
        ]);
    }
}
