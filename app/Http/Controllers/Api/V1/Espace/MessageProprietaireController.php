<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Events\MessagesChangent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\EnvoyerMessageRequest;
use App\Models\Clinique;
use App\Models\MessageClientele;
use App\Support\TempsReel;
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

        // Après le marquage : le fil ouvert ne compte plus de non-lus.
        $nonLus = MessageClientele::query()
            ->where('user_id', $contexte->user()->id)
            ->where('auteur', 'clinique')
            ->whereNull('lu_le')
            ->get()
            ->groupBy('clinique_id')
            ->map->count();

        return response()->json(['data' => [
            'cliniques' => $cliniques->map(fn (Clinique $c): array => [
                'id' => $c->id,
                'nom' => $c->nom,
                'tel' => $c->tel,
                'non_lus' => (int) ($nonLus[$c->id] ?? 0),
                // Réglages de la clinique (28.10.2026) : la raison est dite quand il ne peut pas
                'peut_ecrire' => $this->refus($c, $contexte) === null,
                'raison' => $this->refus($c, $contexte),
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
        $refus = $this->refus($this->cliniques($contexte)->firstWhere('id', $cliniqueId), $contexte);
        if ($refus !== null) {
            throw ValidationException::withMessages(['clinique_id' => $refus]);
        }

        // Sa fiche client dans l'organisation de la clinique : le fil reste attribuable (28.10.2026)
        $organisation = $this->cliniques($contexte)->firstWhere('id', $cliniqueId)?->societe()->withoutGlobalScopes()->value('organisation_id');
        $message = MessageClientele::create([
            'clinique_id' => $cliniqueId,
            'user_id' => $contexte->user()->id,
            'client_id' => $contexte->clients()->firstWhere('organisation_id', $organisation)?->id,
            'auteur' => 'proprietaire',
            'texte' => trim($request->validated('texte')),
        ]);

        // La clinique l'apprend aussitôt (sans le contenu : il se lit par l'API)
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$cliniqueId, $contexte->user()->id));

        return response()->json(['data' => $this->message($message)], 201);
    }

    /** Pourquoi le propriétaire ne peut pas écrire à cette clinique (ses réglages), ou null. */
    private function refus(Clinique $clinique, ContexteProprietaire $contexte): ?string
    {
        $tel = filled($clinique->tel) ? ' : appelez-la au '.$clinique->tel : '';
        if ($clinique->messages_recus === false) {
            return 'La clinique ne reçoit pas de messages'.$tel.'.';
        }
        if ($clinique->messages_reponses_seulement && ! MessageClientele::query()->where('clinique_id', $clinique->id)->where('user_id', $contexte->user()->id)->where('auteur', 'clinique')->exists()) {
            return 'La clinique répond aux messages qu’elle vous envoie'.$tel.'.';
        }

        return null;
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
