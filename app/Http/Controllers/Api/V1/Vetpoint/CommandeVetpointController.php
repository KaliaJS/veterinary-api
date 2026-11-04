<?php

namespace App\Http\Controllers\Api\V1\Vetpoint;

use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Api\V1\CommandeFournisseurController;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ConnexionVetpoint;
use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointOrderSender;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vetpoint, étapes 3 et 4 (demande de Jim, 28.10.2026) : une commande
 * fournisseur part par Vetpoint, au nom de la clinique qui la passe (sa
 * clé, ses adresses, ses prix), en une fois et jamais deux fois ; puis son
 * détail chez Vetpoint (prix réels, péremptions) se lit pour la réception.
 */
class CommandeVetpointController extends Controller
{
    /** Adresses de livraison du compte Vetpoint de la clinique. */
    public function adresses(int $commande): JsonResponse
    {
        $commande = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('update', $commande);

        return response()->json(['data' => $this->appel(fn () => $this->client($commande)->adressesLivraison())]);
    }

    public function envoyer(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('update', $commande);

        $choix = $request->validate([
            'adresse_id' => ['required', 'string', 'max:40'],
            'livraison' => ['required', 'in:standard,express'],
            'date_souhaitee' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'adresse_id.required' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
            'adresse_id.string' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
            'adresse_id.max' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
            'livraison.required' => 'Choisissez une livraison standard ou express.',
            'livraison.in' => 'Choisissez une livraison standard ou express.',
            'date_souhaitee.date_format' => 'Donnez la date souhaitée au format AAAA-MM-JJ.',
        ]);

        return new CommandeFournisseurResource(app(VetpointOrderSender::class)->send($commande, $choix)->load('lignes'));
    }

    /**
     * Un envoi par Vetpoint en suspens (réponse perdue, recherche pas
     * aboutie) : la commande est peut-être partie. Ni envoi classique, ni
     * suppression, ni ligne modifiée ; seul un nouvel envoi par Vetpoint,
     * qui la cherche d'abord, la tranche.
     *
     * @throws ValidationException
     */
    public static function exigerHorsEnvoiEnSuspens(CommandeFournisseur $commande): void
    {
        if ($commande->vetpoint_envoi_le !== null && $commande->vetpoint_commande_id === null) {
            throw ValidationException::withMessages(['statut' => 'Cette commande est peut-être déjà partie par Vetpoint : renvoyez-la par Vetpoint, elle y sera d’abord cherchée.']);
        }
    }

    /**
     * Abandon d'un envoi en suspens (28.10.2026), après vérification sur
     * vetpoint.ch : le brouillon redevient libre (envoi classique,
     * suppression, lignes). Seule issue quand la clé a été retirée ou que
     * Vetpoint ne dit pas si la commande est partie.
     */
    public function abandonner(int $commande): CommandeFournisseurResource
    {
        $commande = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('update', $commande);

        $commande = DB::transaction(function () use ($commande): CommandeFournisseur {
            $verrouillee = CommandeFournisseur::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut !== StatutCommandeFournisseur::Brouillon || $verrouillee->vetpoint_envoi_le === null || $verrouillee->vetpoint_commande_id !== null) {
                throw ValidationException::withMessages(['statut' => 'Aucun envoi par Vetpoint en suspens pour cette commande.']);
            }
            abort_if($verrouillee->vetpoint_envoi_le->greaterThan(now()->subSeconds(VetpointOrderSender::ENVOI_EN_COURS_SECONDES)), 409, 'Cette commande part peut-être en ce moment chez Vetpoint : patientez une minute.');
            $verrouillee->forceFill(['vetpoint_envoi_le' => null, 'vetpoint_adresse_id' => null, 'vetpoint_livraison' => null, 'vetpoint_date_souhaitee' => null])->save();

            return $verrouillee;
        });

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    /** La commande chez Vetpoint : ses lignes avec prix réels et péremptions, dès que Vetpoint les donne. */
    public function detail(int $commande): JsonResponse
    {
        $commande = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('viewAny', CommandeFournisseur::class);
        abort_if($commande->vetpoint_commande_id === null, 404, 'Cette commande n’est pas passée par Vetpoint.');

        $detail = $this->appel(fn () => $this->client($commande)->commande($commande->vetpoint_commande_id));

        return response()->json(['data' => [
            'numero' => $commande->vetpoint_commande_id,
            'date' => is_scalar($detail['orderDate'] ?? null) ? (string) $detail['orderDate'] : null,
            'lignes' => self::lignesDetaillees($commande, $detail),
        ]]);
    }

    /**
     * Les lignes de la commande Vetpoint rapportées aux nôtres, par le lien
     * produit ↔ Vetpoint. Deux articles reliés au même produit Vetpoint :
     * chaque ligne de Vetpoint prend la nôtre de même quantité, sinon la
     * première restante, et jamais une ligne deux fois.
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{ligne_id: int|null, produit_vetpoint: string, quantite: int|null, prix_unitaire: float|null, prix_total: float|null, peremption: string|null}>
     */
    public static function lignesDetaillees(CommandeFournisseur $commande, array $detail): array
    {
        $restantes = $commande->lignes()->with('produit.vetpoint')->orderBy('id')->get()
            ->groupBy(fn (CommandeFournisseurLigne $ligne): string => (string) ($ligne->vetpoint_produit_id ?? $ligne->produit?->vetpoint?->vetpoint_id))
            ->map(fn (Collection $memes): array => $memes->values()->all())->all();
        $elements = is_array($detail['orderItems'] ?? null) ? array_filter($detail['orderItems'], 'is_array') : [];

        $lignes = [];
        foreach ($elements as $element) {
            $produit = is_scalar($element['productId'] ?? null) ? (string) $element['productId'] : '';
            $quantite = is_numeric($element['quantity'] ?? null) ? (int) $element['quantity'] : null;
            $memes = $restantes[$produit] ?? [];
            $rang = array_key_first(array_filter($memes, fn (CommandeFournisseurLigne $ligne): bool => (int) $ligne->quantite === $quantite)) ?? array_key_first($memes);
            if ($rang !== null) {
                unset($restantes[$produit][$rang]);
            }
            $peremption = is_string($element['expirationDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $element['expirationDate']) === 1
                ? substr($element['expirationDate'], 0, 10)
                : (is_scalar($element['expirationDate'] ?? null) ? (string) $element['expirationDate'] : null);

            $lignes[] = [
                'ligne_id' => $rang !== null ? $memes[$rang]->id : null,
                'produit_vetpoint' => $produit,
                'quantite' => $quantite,
                'prix_unitaire' => ClientVetpoint::nombre($element['unitPrice'] ?? null),
                'prix_total' => ClientVetpoint::nombre($element['totalPrice'] ?? null),
                'peremption' => $peremption,
            ];
        }

        return $lignes;
    }

    /**
     * Le client Vetpoint de la clinique, ou un refus clair. La commande
     * est celle de la clinique du jeton : c'est sa clé qui part, jamais
     * celle d'une clinique sœur.
     */
    public static function clientDe(int $cliniqueId): ClientVetpoint
    {
        $connexion = ConnexionVetpoint::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->first();
        abort_if($connexion === null, 409, 'Vetpoint n’est pas relié à cette clinique : collez sa clé dans Réglages, Fournisseurs.');
        $cle = $connexion->cleEnClair();
        abort_if($cle === null, 409, 'La clé Vetpoint de la clinique est illisible : collez-la à nouveau dans Réglages, Fournisseurs.');

        return ClientVetpoint::pour($cle);
    }

    private function client(CommandeFournisseur $commande): ClientVetpoint
    {
        return self::clientDe($commande->clinique_id);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $appel
     * @return T
     */
    private function appel(Closure $appel): mixed
    {
        return app(VetpointOrderSender::class)->call($appel);
    }
}
