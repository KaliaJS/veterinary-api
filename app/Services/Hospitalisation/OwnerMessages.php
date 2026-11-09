<?php

namespace App\Services\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\PushType;
use App\Events\MessagesChangent;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\MessageClientele;
use App\Models\PreferencesProprietaire;
use App\Models\Sejour;
use App\Services\Notifications\Push;
use App\Support\TempsReel;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Un écrit part au propriétaire (rapport de la journée, 03.11.2026 ; lettre
 * de sortie et partage du dossier, 09.11.2026) : un message de la clinique dans sa
 * messagerie, créé dans la transaction de l'envoi, puis l'annonce, la
 * notification et l'e-mail (s'il ne l'a pas coupé), après.
 */
final class OwnerMessages
{
    /** Le propriétaire qui a confié l'animal, figé à l'admission */
    public function owner(Sejour $sejour): ?Client
    {
        return $sejour->client_id !== null ? Client::query()->find($sejour->client_id) : $sejour->patient?->client;
    }

    /**
     * Le message, dans la transaction de l'envoi
     *
     * @return array{0: MessageClientele, 1: bool} le message, et s'il est le premier de cette clinique
     */
    public function create(Sejour $sejour, Client $client, string $text): array
    {
        return $this->createFor((int) $sejour->clinique_id, $client, $text);
    }

    /**
     * Le message d'une clinique, hors séjour (le partage du dossier, 09.11.2026)
     *
     * @return array{0: MessageClientele, 1: bool}
     */
    public function createFor(int $cliniqueId, Client $client, string $text): array
    {
        $first = ! MessageClientele::query()->where('clinique_id', $cliniqueId)->where('user_id', $client->user_id)->exists();
        $message = MessageClientele::create([
            'clinique_id' => $cliniqueId,
            'user_id' => $client->user_id,
            'client_id' => $client->id,
            'employe_id' => app(ContexteClinique::class)->employe()?->id,
            'auteur' => 'clinique',
            'texte' => $text,
        ]);

        return [$message, $first];
    }

    /** Après la transaction : les écrans, le téléphone et l'e-mail du propriétaire */
    public function notify(Sejour $sejour, Client $client, MessageClientele $message, string $text, bool $first): void
    {
        $this->notifyFor((int) $sejour->clinique_id, $client, $message, $text, $first);
    }

    public function notifyFor(int $cliniqueId, Client $client, MessageClientele $message, string $text, bool $first): void
    {
        $contexte = app(ContexteClinique::class);
        $userId = (int) $client->user_id;
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId, $userId));
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$cliniqueId, $userId));
        Push::to([$userId], PushType::ClinicMessage, (string) $contexte->clinique()?->nom, mb_strimwidth($text, 0, 120, '…'), '/espace/messages');
        if ($client->user !== null && PreferencesProprietaire::query()->where('user_id', $userId)->value('reponses_clinique') !== false) {
            try {
                Mail::to($client->user->email)->send(new ReponseClinique($message, $contexte->clinique(), $first));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
