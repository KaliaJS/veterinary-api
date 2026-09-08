<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\EnvoyerMessageRequest;
use App\Models\Clinique;
use App\Models\MessageClientele;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Messages (proto : un fil avec « la clinique », réponse sous 24 h).
 * Un compte peut être client de plusieurs cliniques : un fil par
 * clinique, parmi les cliniques des organisations où le compte a une
 * fiche. Ouvrir un fil marque lus les messages de la clinique.
 */
class MessageProprietaireController extends Controller
{
    /** Fils disponibles + fil demandé (ou le premier), avec ses messages. */
    public function index(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $cliniques = $this->cliniques($contexte);
        $cliniqueId = $request->integer('clinique_id') ?: $cliniques->first()?->id;
        $clinique = $cliniques->firstWhere('id', $cliniqueId);

        $nonLus = MessageClientele::query()
            ->where('user_id', $contexte->user()->id)
            ->where('auteur', 'clinique')
            ->whereNull('lu_le')
            ->get()
            ->groupBy('clinique_id')
            ->map->count();

        $messages = collect();
        if ($clinique) {
            MessageClientele::query()
                ->where('user_id', $contexte->user()->id)
                ->where('clinique_id', $clinique->id)
                ->where('auteur', 'clinique')
                ->whereNull('lu_le')
                ->update(['lu_le' => now()]);

            $messages = MessageClientele::query()
                ->with('employe')
                ->where('user_id', $contexte->user()->id)
                ->where('clinique_id', $clinique->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        return response()->json(['data' => [
            'cliniques' => $cliniques->map(fn (Clinique $c): array => [
                'id' => $c->id,
                'nom' => $c->nom,
                'tel' => $c->tel,
                'non_lus' => (int) ($nonLus[$c->id] ?? 0),
            ])->values()->all(),
            'clinique_id' => $clinique?->id,
            'messages' => $messages->map(fn (MessageClientele $m): array => $this->message($m))->all(),
        ]]);
    }

    public function envoyer(EnvoyerMessageRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $cliniqueId = (int) $request->validated('clinique_id');

        if (! $this->cliniques($contexte)->contains('id', $cliniqueId)) {
            throw ValidationException::withMessages(['clinique_id' => 'Vous n’êtes pas client de cette clinique.']);
        }

        $message = MessageClientele::create([
            'clinique_id' => $cliniqueId,
            'user_id' => $contexte->user()->id,
            'auteur' => 'proprietaire',
            'texte' => trim($request->validated('texte')),
        ]);

        return response()->json(['data' => $this->message($message)], 201);
    }

    /**
     * Cliniques des organisations où le compte a une fiche.
     *
     * @return Collection<int, Clinique>
     */
    private function cliniques(ContexteProprietaire $contexte)
    {
        $organisations = $contexte->clients()->pluck('organisation_id')->unique()->all();

        if ($organisations === []) {
            return collect();
        }

        return Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($q) => $q->whereIn('organisation_id', $organisations))
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function message(MessageClientele $m): array
    {
        return [
            'id' => $m->id,
            'auteur' => $m->auteur,
            'de_moi' => $m->auteur === 'proprietaire',
            'signature' => $m->auteur === 'clinique' ? ($m->employe?->nom) : null,
            'texte' => $m->texte,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->lu_le?->toIso8601String(),
        ];
    }
}
