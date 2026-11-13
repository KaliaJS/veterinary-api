<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\Patients\OneAccountPerPet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le compte espace client d'une fiche. Un seul geste la rattache à un
 * compte : l'invitation prise par son titulaire, connecté avec l'adresse
 * invitée (03.11.2026). « Ouvrir un compte » depuis la fiche sur une
 * adresse tapée (24.10.2026) est retiré (audit C01, 13.11.2026) : il
 * rattachait un compte existant sans son accord et recopiait ses
 * coordonnées dans la fiche.
 */
class OwnerAccount
{
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
