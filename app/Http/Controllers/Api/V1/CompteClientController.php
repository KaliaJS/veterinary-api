<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clients\CreerCompteClientRequest;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Response;
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

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Un compte existe déjà avec cet e-mail.',
            ]);
        }

        $user = User::create([
            'name' => trim($client->prenom.' '.$client->nom),
            'email' => $email,
            'password' => Str::password(40),
        ]);

        $client->update(['user_id' => $user->id]);

        return new ClientResource($client);
    }

    /**
     * Révocation : le compte est détaché puis supprimé s'il ne sert
     * qu'au portail (jamais un compte employé), jetons révoqués.
     */
    public function supprimer(Client $client): Response
    {
        $this->authorize('update', $client);

        $user = $client->user;

        $client->update(['user_id' => null]);

        if ($user !== null && $user->employes()->doesntExist()) {
            $user->tokens()->delete();
            $user->delete();
        }

        return response()->noContent();
    }
}
