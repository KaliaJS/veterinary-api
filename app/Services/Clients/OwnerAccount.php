<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\Patients\OneAccountPerPet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Le compte espace client d'une fiche. Deux gestes seulement la rattachent
 * à un compte, jamais un rattachement automatique par e-mail :
 * - `open` : « ouvrir un compte » depuis la fiche (décision du 24.10.2026),
 *   la fiche va au compte de cette adresse, ou un compte naît ;
 * - `attach` : l'invitation prise par son titulaire, connecté avec
 *   l'adresse invitée (03.11.2026).
 */
class OwnerAccount
{
    public static function open(Client $client, string $email): User
    {
        $email = mb_strtolower(trim($email));
        // Le même travail dans les deux cas (24.10.2026, décidé avec Jim) :
        // hacher un mot de passe prend un quart de seconde ; fait aussi quand
        // le compte existe, le temps de réponse ne dit plus si l'adresse en a un
        $password = Hash::make(Str::password(40));

        return DB::transaction(function () use ($client, $email, $password): User {
            $user = User::query()->where('email', $email)->first() ?? User::create([
                'name' => trim($client->prenom.' '.$client->nom),
                'email' => $email,
                'password' => $password,
            ]);
            self::attach($client, $user);

            return $user;
        });
    }

    /**
     * La fiche rattachée au compte. Un animal, un seul compte (02.11.2026) :
     * vérifié avant tout, la fiche verrouillée (une acceptation de
     * réclamation en même temps attend). Les coordonnées gardées sur le
     * compte remplissent ce que la fiche n'a pas (28.10.2026).
     */
    public static function attach(Client $client, User $user): void
    {
        DB::transaction(function () use ($client, $user): void {
            Client::withoutGlobalScopes()->whereKey($client->id)->lockForUpdate()->first();
            if (($pet = OneAccountPerPet::petOfAnotherAccount($client->id, $user->id)) !== null) {
                throw ValidationException::withMessages([
                    'client' => $pet->nom.' est déjà dans l’espace d’un autre compte (réclamé par sa puce). Un animal n’appartient qu’à un seul compte.',
                ]);
            }

            $client->update(['user_id' => $user->id, ...collect(['tel', 'rue', 'npa', 'localite'])
                ->filter(fn (string $field): bool => blank($client->{$field}) && filled($user->{$field}))
                ->mapWithKeys(fn (string $field): array => [$field => $user->{$field}])->all()]);
        });
    }
}
