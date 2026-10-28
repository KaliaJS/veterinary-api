<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Events\MessagesChangent;
use App\Http\Controllers\Controller;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\MessageClientele;
use App\Models\PreferencesProprietaire;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Messagerie de la clinique (décision de Jim, 28.10.2026) : les fils de
 * ses clients, les non-lus, la réponse signée de l'employé ; la clinique
 * peut aussi ouvrir un fil. Un fil se désigne par sa clé : « u12 » (le
 * compte du client) ou « c34 » (sa fiche, quand le compte a été effacé).
 * Un compte effacé ou détaché de sa fiche garde son fil lisible ; on n'y
 * répond plus. Le propriétaire est prévenu en temps réel, et par e-mail
 * s'il l'a choisi.
 */
class MessagerieCliniqueController extends Controller
{
    /** Les fils de la clinique, le plus récent d'abord. */
    public function index(): JsonResponse
    {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $messages = MessageClientele::query()->where('clinique_id', $cliniqueId)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (MessageClientele $m): string => $m->user_id !== null ? 'u'.$m->user_id : 'c'.$m->client_id);
        // Les fiches de l'organisation : par compte (lié) et par fiche (gardée sur les messages)
        $parCompte = Client::query()->whereIn('user_id', $messages->flatten()->pluck('user_id')->filter()->unique())->get()->keyBy('user_id');
        $parFiche = Client::query()->whereIn('id', $messages->flatten()->pluck('client_id')->filter()->unique())->get()->keyBy('id');

        $fils = $messages->map(function ($fil, string $cle) use ($parCompte, $parFiche): array {
            /** @var MessageClientele $dernier */
            $dernier = $fil->first();
            $lie = $dernier->user_id !== null ? ($parCompte[$dernier->user_id] ?? null) : null;
            $fiche = $lie ?? ($parFiche[$fil->pluck('client_id')->filter()->first()] ?? null);

            return [
                'cle' => $cle,
                'user_id' => $dernier->user_id,
                'client_id' => $fiche?->id,
                'client' => $fiche ? trim($fiche->prenom.' '.$fiche->nom) : null,
                'peut_repondre' => $lie !== null,
                'non_lus' => $fil->where('auteur', 'proprietaire')->whereNull('lu_le')->count(),
                'dernier' => ['texte' => mb_strimwidth($dernier->texte, 0, 140, '…'), 'auteur' => $dernier->auteur, 'envoye_le' => $dernier->created_at?->toIso8601String()],
            ];
        })->values();

        return response()->json(['data' => $fils]);
    }

    /** Un fil : ses messages, ceux du propriétaire marqués lus. */
    public function fil(string $cle): JsonResponse
    {
        [$fiche, $userId, $lie] = $this->fiche($cle);
        $this->messagesDu($cle, $fiche)->where('auteur', 'proprietaire')->whereNull('lu_le')->update(['lu_le' => now()]);
        $messages = $this->messagesDu($cle, $fiche)->with('employe')->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => [
            'cle' => $cle,
            'user_id' => $userId,
            'client' => trim($fiche->prenom.' '.$fiche->nom),
            'client_id' => $fiche->id,
            'peut_repondre' => $lie,
            'messages' => $messages->map(fn (MessageClientele $m): array => $this->message($m))->all(),
        ]]);
    }

    /** Répondre, ou ouvrir un fil avec un client qui a un compte. */
    public function repondre(Request $request, string $cle): JsonResponse
    {
        [$fiche, $userId, $lie] = $this->fiche($cle);
        if (! $lie || $userId === null) {
            throw ValidationException::withMessages(['texte' => 'Ce client n’a plus de compte lié : il ne lirait pas ce message. Appelez-le.']);
        }
        $texte = trim((string) $request->validate(['texte' => ['required', 'string', 'max:2000']])['texte']);
        $contexte = app(ContexteClinique::class);
        $message = MessageClientele::create([
            'clinique_id' => $contexte->cliniqueId(),
            'user_id' => $userId,
            'client_id' => $fiche->id,
            'employe_id' => $contexte->employe()?->id,
            'auteur' => 'clinique',
            'texte' => $texte,
        ]);
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId, $userId));
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$contexte->cliniqueId(), $userId));
        // Par e-mail aussi, s'il ne l'a pas coupé (« Réponses de la clinique »)
        $proprietaire = $fiche->user;
        if ($proprietaire !== null && PreferencesProprietaire::query()->where('user_id', $userId)->value('reponses_clinique') !== false) {
            Mail::to($proprietaire->email)->send(new ReponseClinique($message, $contexte->clinique()));
        }

        return response()->json(['data' => $this->message($message->load('employe'))], 201);
    }

    /**
     * La fiche du fil, dans l'organisation (scope) : par le compte lié, ou
     * par la fiche gardée sur ses messages ; sinon, ce fil n'existe pas ici.
     *
     * @return array{0: Client, 1: ?int, 2: bool} fiche, compte, compte encore lié
     */
    private function fiche(string $cle): array
    {
        $id = (int) substr($cle, 1);
        if ($cle[0] === 'c') {
            return [Client::query()->findOrFail($id), null, false];
        }
        $lie = Client::query()->where('user_id', $id)->first();
        if ($lie !== null) {
            return [$lie, $id, true];
        }
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $ficheId = MessageClientele::query()->where('clinique_id', $cliniqueId)->where('user_id', $id)->whereNotNull('client_id')->value('client_id');

        return [Client::query()->findOrFail($ficheId ?? 0), $id, false];
    }

    /** @return Builder<MessageClientele> */
    private function messagesDu(string $cle, Client $fiche): Builder
    {
        $requete = MessageClientele::query()->where('clinique_id', app(ContexteClinique::class)->cliniqueId());

        return $cle[0] === 'c'
            ? $requete->whereNull('user_id')->where('client_id', $fiche->id)
            : $requete->where('user_id', (int) substr($cle, 1));
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
