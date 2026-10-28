<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clients\CreerCompteClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compte espace client (0..1 par propriétaire) — conditionne le canal
 * d'envoi des devis (portail vs papier) et les notifications. Aucun
 * mot de passe communiqué : l'espace client s'authentifie comme le
 * reste (passkey ou code e-mail).
 */
class CompteClientController extends Controller
{
    public function creer(CreerCompteClientRequest $request, Client $client): ClientResource
    {
        $this->authorize('update', $client);

        if ($client->user_id !== null) {
            throw ValidationException::withMessages([
                'client' => 'Ce propriétaire a déjà un compte espace client.',
            ]);
        }

        $email = $request->validated('email') ?? $client->email;

        if ($email === null) {
            throw ValidationException::withMessages([
                'email' => 'Un e-mail est requis pour ouvrir un compte espace client.',
            ]);
        }

        // Un compte existe déjà à cette adresse : la fiche s'y rattache
        // (24.10.2026). La réponse est la même qu'à la création : la clinique
        // ne peut pas tester quelles adresses ont un compte. Le titulaire y
        // retrouve cette clinique, comme les autres.
        // Le même travail dans les deux cas (24.10.2026, décidé avec Jim) :
        // hacher un mot de passe prend un quart de seconde ; fait aussi quand
        // le compte existe, le temps de réponse ne dit plus si l'adresse en a un
        $email = mb_strtolower(trim($email));
        $motDePasse = Hash::make(Str::password(40));
        $user = User::query()->where('email', $email)->first() ?? User::create([
            'name' => trim($client->prenom.' '.$client->nom),
            'email' => $email,
            'password' => $motDePasse,
        ]);

        // Les coordonnées gardées sur le compte remplissent ce que la fiche n'a pas (28.10.2026)
        $client->update(['user_id' => $user->id, ...collect(['tel', 'rue', 'npa', 'localite'])
            ->filter(fn (string $champ): bool => blank($client->{$champ}) && filled($user->{$champ}))
            ->mapWithKeys(fn (string $champ): array => [$champ => $user->{$champ}])->all()]);

        return new ClientResource($client);
    }

    /**
     * Révocation : le compte est détaché de la fiche. Il n'est supprimé
     * que s'il ne servait qu'à cette fiche (23.10.2026) : ni employé, ni
     * client d'une autre organisation, ni animal à lui, ni abonnement,
     * achats, messages ou demandes ; sinon il reste à son titulaire.
     */
    public function supprimer(Client $client): Response
    {
        $this->authorize('update', $client);

        $user = $client->user;

        DB::transaction(function () use ($client, $user): void {
            $client->update(['user_id' => null]);

            if ($user !== null && ! self::aDAutresAttaches($user)) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        return response()->noContent();
    }

    /** Le compte sert-il ailleurs que pour cette fiche ? (hors de tout scope) */
    private static function aDAutresAttaches(User $user): bool
    {
        $tables = [
            'employes' => 'user_id', 'clients' => 'user_id', 'patients' => 'proprietaire_id',
            'proprietaire_factures' => 'user_id', 'achats_planete' => 'user_id', 'messages_clientele' => 'user_id',
            'demandes_rattachement' => 'user_id', 'demandes_rendez_vous' => 'user_id',
            'demandes_renouvellement_ordonnance' => 'user_id', 'specialites_veterinaire' => 'user_id',
        ];

        foreach ($tables as $table => $colonne) {
            if (DB::table($table)->where($colonne, $user->id)->exists()) {
                return true;
            }
        }

        // Abonné (Stripe ou App Store), modérateur, identité payée, GLN, ou
        // adresse prouvée par son titulaire : un compte qui vit par lui-même,
        // pas seulement par cette fiche (24.10.2026)
        return $user->email_verified_at !== null
            || $user->stripe_client_id !== null
            || $user->apple_abonnement_id !== null
            || $user->est_moderateur
            || $user->identite_payee_le !== null
            || $user->gln !== null
            || $user->passkeys()->exists();
    }
}
