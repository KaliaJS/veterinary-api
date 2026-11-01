<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Vérifier l'authenticité d'une ordonnance, sans compte (01.11.2026,
 * demande de Jim) : le pharmacien scanne le code QR, ou tape sur
 * veterinary.app/ordo le numéro et le code à quatre chiffres imprimés.
 * Les deux ensemble : un code seul se devinerait. La réponse dit
 * seulement ce qu'il faut pour comparer avec le papier (clinique,
 * prescripteur, animal, médicaments, état), jamais le détenteur.
 *
 * Deux portes : le code QR porte un jeton long et secret (`t`), qui ne se
 * devine pas ; à la main, le numéro et le code à quatre chiffres (choix de
 * Jim). À la main, les essais faux sont comptés par numéro (10 par heure)
 * et par adresse (10 par heure) : on ne peut ni essayer tous les codes
 * d'une ordonnance, ni parcourir les numéros.
 */
class VerificationOrdonnanceController extends Controller
{
    private const int ESSAIS_PAR_NUMERO = 10;

    private const int ESSAIS_PAR_ADRESSE = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $relations = ['lignes.produit' => fn ($requete) => $requete->withoutGlobalScopes(), 'clinique.societe', 'employe' => fn ($requete) => $requete->withoutGlobalScopes(), 'employe.user', 'patient' => fn ($requete) => $requete->withoutGlobalScopes()];

        // Le code QR : le jeton long suffit
        $jeton = (string) $request->query('t', '');
        if ($jeton !== '') {
            $ordonnance = strlen($jeton) === 32 ? Ordonnance::withoutGlobalScopes()->with($relations)->where('jeton_verification', $jeton)->first() : null;
            abort_if($ordonnance === null, 404, 'Ce code QR ne correspond à aucune ordonnance.');

            return $this->reponse($ordonnance);
        }

        // À la main : le numéro (lettres, chiffres et tirets seulement, pour que la clé du compteur soit celle de la recherche) et le code
        $numero = (string) preg_replace('/[^A-Z0-9-]/', '', mb_strtoupper((string) $request->query('numero', '')));
        $code = (string) preg_replace('/\D/', '', (string) $request->query('code', ''));

        $cleNumero = 'verification-ordonnance:'.$numero;
        $cleAdresse = 'verification-ordonnance-adresse:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($cleNumero, self::ESSAIS_PAR_NUMERO), 429, 'Trop d’essais pour cette ordonnance : réessayez dans une heure, scannez le code QR, ou appelez la clinique.');
        abort_if(RateLimiter::tooManyAttempts($cleAdresse, self::ESSAIS_PAR_ADRESSE), 429, 'Trop d’essais faux : réessayez dans une heure, scannez le code QR, ou appelez la clinique.');

        $ordonnance = strlen($code) !== 4 || $numero === '' ? null : Ordonnance::withoutGlobalScopes()
            ->with($relations)
            ->where('code_verification', $code)
            ->where('numero', $numero)
            ->first();
        if ($ordonnance === null) {
            RateLimiter::hit($cleNumero, 3600);
            RateLimiter::hit($cleAdresse, 3600);
            abort(404, 'Aucune ordonnance ne correspond à ce numéro et à ce code.');
        }

        return $this->reponse($ordonnance);
    }

    private function reponse(Ordonnance $ordonnance): JsonResponse
    {
        $utilisateur = $ordonnance->employe?->user;
        $renouvellement = Ordonnance::withoutGlobalScopes()->where('ordonnance_precedente_id', $ordonnance->id)->first(['numero', 'signee_le']);
        $enTete = $ordonnance->clinique?->enTeteDocument() ?? [];

        return response()->json(['data' => [
            'numero' => $ordonnance->numero,
            'signee_le' => $ordonnance->signee_le->toDateString(),
            'statut' => $ordonnance->statut->value,
            // Renouvelée : elle a été remplacée par une nouvelle ordonnance
            'renouvelee_par' => $renouvellement ? ['numero' => $renouvellement->numero, 'signee_le' => $renouvellement->signee_le->toDateString()] : null,
            'est_valable' => $ordonnance->statut === StatutOrdonnance::Active,
            'clinique' => [
                'nom' => $enTete['nom'] ?? null,
                'adresse' => $enTete['adresse'] ?? null,
                'contact' => $enTete['contact'] ?? null,
            ],
            'prescripteur' => [
                'nom' => $ordonnance->employe?->nom,
                'titre' => $ordonnance->employe?->role_libelle,
                'gln' => $utilisateur?->gln_verifie_le !== null ? $utilisateur->gln : null,
            ],
            'animal' => $ordonnance->patient ? ['nom' => $ordonnance->patient->nom, 'espece' => $ordonnance->patient->espece->libelle()] : null,
            'renouvellements' => $ordonnance->renouvellements,
            'lignes' => $ordonnance->lignes->map(fn (OrdonnanceLigne $ligne): array => [
                'produit' => $ligne->produit?->nom,
                'quantite' => $ligne->quantite,
                'posologie' => $ligne->posologie,
                'duree' => $ligne->duree,
            ])->values()->all(),
        ]]);
    }
}
