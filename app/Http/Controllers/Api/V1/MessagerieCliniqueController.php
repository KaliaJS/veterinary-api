<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Events\MessagesChangent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MessageClientele;
use App\Support\TempsReel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Messagerie de la clinique (décision de Jim, 28.10.2026) : les fils de
 * ses clients avec un compte (un par compte), les non-lus, la réponse
 * signée de l'employé. La clinique peut aussi ouvrir un fil. Le
 * propriétaire est prévenu en temps réel sur son canal.
 */
class MessagerieCliniqueController extends Controller
{
    /** Les fils de la clinique, le plus récent d'abord. */
    public function index(): JsonResponse
    {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $messages = MessageClientele::query()->where('clinique_id', $cliniqueId)->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('user_id');
        $fiches = Client::query()->whereIn('user_id', $messages->keys())->get()->keyBy('user_id');

        $fils = $messages->map(function ($fil, $userId) use ($fiches): array {
            /** @var MessageClientele $dernier */
            $dernier = $fil->first();

            return [
                'user_id' => (int) $userId,
                'client' => $fiches[$userId] ?? null ? trim($fiches[$userId]->prenom.' '.$fiches[$userId]->nom) : null,
                'client_id' => $fiches[$userId]->id ?? null,
                'non_lus' => $fil->where('auteur', 'proprietaire')->whereNull('lu_le')->count(),
                'dernier' => ['texte' => mb_strimwidth($dernier->texte, 0, 140, '…'), 'auteur' => $dernier->auteur, 'envoye_le' => $dernier->created_at?->toIso8601String()],
            ];
        })->values();

        return response()->json(['data' => $fils]);
    }

    /** Un fil : ses messages, ceux du propriétaire marqués lus. */
    public function fil(int $compte): JsonResponse
    {
        $fiche = $this->fiche($compte);
        $user = $fiche->user()->firstOrFail();
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        MessageClientele::query()->where('clinique_id', $cliniqueId)->where('user_id', $user->id)->where('auteur', 'proprietaire')->whereNull('lu_le')->update(['lu_le' => now()]);
        $messages = MessageClientele::query()->with('employe')->where('clinique_id', $cliniqueId)->where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => [
            'user_id' => $user->id,
            'client' => trim($fiche->prenom.' '.$fiche->nom),
            'client_id' => $fiche->id,
            'messages' => $messages->map(fn (MessageClientele $m): array => $this->message($m))->all(),
        ]]);
    }

    /** Répondre, ou ouvrir un fil avec un client qui a un compte. */
    public function repondre(Request $request, int $compte): JsonResponse
    {
        $fiche = $this->fiche($compte);
        $user = $fiche->user()->firstOrFail();
        $texte = trim((string) $request->validate(['texte' => ['required', 'string', 'max:2000']])['texte']);
        $contexte = app(ContexteClinique::class);
        $message = MessageClientele::create([
            'clinique_id' => $contexte->cliniqueId(),
            'user_id' => $user->id,
            'client_id' => $fiche->id,
            'employe_id' => $contexte->employe()?->id,
            'auteur' => 'clinique',
            'texte' => $texte,
        ]);
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$user->id, $user->id));
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$contexte->cliniqueId(), $user->id));

        return response()->json(['data' => $this->message($message->load('employe'))], 201);
    }

    /** Le compte doit être client de l'organisation (fiche au scope de l'organisation) : sinon, ce fil n'existe pas ici. */
    private function fiche(int $compte): Client
    {
        return Client::query()->where('user_id', $compte)->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function message(MessageClientele $m): array
    {
        return [
            'id' => $m->id,
            'auteur' => $m->auteur,
            'signature' => $m->auteur === 'clinique' ? $m->employe?->nom : null,
            'texte' => $m->texte,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->lu_le?->toIso8601String(),
        ];
    }
}
