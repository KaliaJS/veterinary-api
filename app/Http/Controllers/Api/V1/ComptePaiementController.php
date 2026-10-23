<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Models\Societe;
use App\Paiements\ErreurPaiement;
use App\Services\Paiements\ComptesPaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Compte de paiement de la société de la clinique du jeton (23.10.2026) :
 * son état, son ouverture, et les sessions des composants intégrés
 * (inscription et gestion du compte : domaine organisation ; bannière,
 * paiements, virements, solde : domaine facturation).
 */
class ComptePaiementController extends Controller
{
    public function __construct(private ComptesPaiement $comptes) {}

    public function afficher(): JsonResponse
    {
        $societe = $this->societe();

        return $this->reponse($societe->stripe_compte_id ? $this->sansErreur(fn () => $this->comptes->rafraichir($societe)) : $societe);
    }

    /**
     * Ouvre le compte (administrateur de l'organisation), qui accepte dans
     * l'app les conditions du prestataire : l'inscription suit, dans l'app.
     */
    public function ouvrir(Request $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Organisation->value);
        $request->validate(['conditions' => ['accepted']], ['conditions.accepted' => 'Acceptez les conditions des paiements pour activer le compte.']);

        return $this->reponse($this->sansErreur(fn () => $this->comptes->ouvrir($this->societe(), $request->user()->email, [
            'date' => now(),
            'ip' => (string) $request->ip(),
            'navigateur' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 500) : null,
        ])));
    }

    public function session(Request $request): JsonResponse
    {
        $composants = $request->validate([
            'composants' => ['required', 'array', 'min:1'],
            'composants.*' => [Rule::in([...ComptesPaiement::COMPOSANTS_ORGANISATION, ...ComptesPaiement::COMPOSANTS_FACTURATION])],
        ])['composants'];

        if (array_intersect($composants, ComptesPaiement::COMPOSANTS_ORGANISATION) !== []) {
            Gate::authorize(DomainePermission::Organisation->value);
        }

        return response()->json(['data' => [
            'client_secret' => $this->sansErreur(fn () => $this->comptes->session($this->societe(), $composants)),
            'cle_publique' => config('services.stripe.key'),
        ]]);
    }

    /**
     * Le prestataire refuse ou ne répond pas : un message, pas une erreur serveur.
     *
     * @template T
     *
     * @param  callable(): T  $appel
     * @return T
     */
    private function sansErreur(callable $appel): mixed
    {
        try {
            return $appel();
        } catch (ErreurPaiement $e) {
            report($e);

            throw ValidationException::withMessages(['paiements' => 'Le service de paiement ne répond pas comme prévu. Réessayez dans un instant.']);
        }
    }

    private function societe(): Societe
    {
        return app(ContexteClinique::class)->clinique()->societe;
    }

    private function reponse(Societe $societe): JsonResponse
    {
        return response()->json(['data' => [
            'societe' => ['id' => $societe->id, 'nom' => $societe->nom],
            'ouvert' => $societe->stripe_compte_id !== null,
            'paiements_actifs' => $societe->paiements_actifs,
            'virements_actifs' => $societe->virements_actifs,
            'a_completer' => $societe->paiements_a_completer,
            // Ce que la société paie par encaissement (frais compris)
            'tarifs' => [
                'terminal' => ComptesPaiement::tarif('terminal'),
                'twint' => ComptesPaiement::tarif('twint'),
                'en_ligne' => ComptesPaiement::tarif('en_ligne'),
            ],
            'peut_gerer' => Gate::allows(DomainePermission::Organisation->value),
        ]]);
    }
}
