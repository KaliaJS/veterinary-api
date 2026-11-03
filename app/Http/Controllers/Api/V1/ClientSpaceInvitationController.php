<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use App\Services\Clients\OwnerSpaceInvitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'invitation dans l'espace vue par la clinique (03.11.2026) : l'envoyer
 * ou la renvoyer (nouveau lien, l'ancien ne vaut plus rien), l'annuler.
 * Équipe avec le droit `patients`, fiches de son organisation seulement.
 */
class ClientSpaceInvitationController extends Controller
{
    public function store(Client $client): ClientResource
    {
        $this->authorize('update', $client);

        $clinic = app(ContexteClinique::class)->clinique();
        if ($clinic === null) {
            throw ValidationException::withMessages(['client' => 'Une invitation part au nom d’une clinique : ouvrez la session d’une clinique.']);
        }

        // Fiche verrouillée et relue : un rattachement ou un changement
        // d'adresse arrivé entre-temps est vu avant d'envoyer (03.11.2026)
        $locked = DB::transaction(function () use ($client, $clinic): Client {
            $locked = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== null) {
                throw ValidationException::withMessages(['client' => 'Ce propriétaire a déjà son espace : il y règle ses rappels.']);
            }
            if (blank($locked->email)) {
                throw ValidationException::withMessages(['email' => 'Pour l’inviter dans son espace, il faut son e-mail.']);
            }
            OwnerSpaceInvitations::invite($locked, (string) $locked->email, $clinic);

            return $locked;
        });

        return new ClientResource($locked->refresh());
    }

    public function destroy(Client $client): ClientResource
    {
        $this->authorize('update', $client);

        OwnerSpaceInvitations::cancel($client);

        return new ClientResource($client->refresh());
    }
}
