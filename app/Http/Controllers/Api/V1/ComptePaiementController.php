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

    /** Ouvre le compte (administrateur de l'organisation) : l'inscription suit, dans l'app. */
    public function ouvrir(Request $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Organisation->value);

        return $this->reponse($this->sansErreur(fn () => $this->comptes->ouvrir($this->societe(), $request->user()->email)));
    }

    /**
     * Paiements intégrés ou non (administrateur) : une clinique peut garder
     * son propre terminal (Worldline…) ; l'équipe marque alors les paiements
     * par carte et TWINT comme payés, et l'acompte en ligne n'est pas demandé.
     */
    public function integration(Request $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Organisation->value);
        $integres = $request->validate(['integres' => ['required', 'boolean']])['integres'];
        app(ContexteClinique::class)->clinique()->update(['paiements_integres' => $integres]);

        return $this->reponse($this->societe()->refresh());
    }

    /** Pour la caisse, sans appel au prestataire : carte et TWINT passent-ils par Veterinary ? */
    public function mode(): JsonResponse
    {
        $clinique = app(ContexteClinique::class)->clinique();

        return response()->json(['data' => [
            'integre' => $clinique->encaisseAvecVeterinary(),
            'paiements_integres' => (bool) $clinique->paiements_integres,
        ]]);
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
            'integres' => (bool) app(ContexteClinique::class)->clinique()->paiements_integres,
            'ouvert' => $societe->stripe_compte_id !== null,
            'paiements_actifs' => $societe->paiements_actifs,
            'virements_actifs' => $societe->virements_actifs,
            'a_completer' => $societe->paiements_a_completer,
            'commission_terminal' => (float) config('services.stripe.commission_terminal'),
            'commission_twint' => (float) config('services.stripe.commission_twint'),
            'commission_en_ligne' => (float) config('services.stripe.commission_en_ligne'),
            'frais_stripe' => config('services.stripe.frais_stripe'),
            'peut_gerer' => Gate::allows(DomainePermission::Organisation->value),
        ]]);
    }
}
