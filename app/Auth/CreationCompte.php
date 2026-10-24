<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Compte créé par la connexion par code e-mail, pour une adresse
 * inconnue : le code reçu prouve l'adresse. C'est la seule façon de
 * s'inscrire (24.10.2026) ; la passkey s'ajoute ensuite, connecté.
 * Sans rattachement clinique : la session est « sans clinique » tant
 * qu'une organisation ne l'embauche pas (invitation acceptée). Le nom
 * est provisoire (partie locale de l'e-mail) ; le mot de passe, jamais
 * utilisé, est un aléa.
 */
class CreationCompte
{
    public function creer(string $email): User
    {
        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(40),
            'email_verified_at' => now(),
        ]);
    }
}
