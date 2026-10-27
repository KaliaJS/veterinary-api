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
use App\Services\Vetpoint\VetpointCgv;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use App\Services\Vetpoint\VetpointRefus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vetpoint, étapes 3 et 4 (demande de Jim, 28.10.2026) : une commande
 * fournisseur part par Vetpoint, au nom de la clinique de livraison (sa
 * clé, ses adresses, ses prix), en une fois et jamais deux fois ; puis son
 * détail chez Vetpoint (prix réels, péremptions) se lit pour la réception.
 */
class CommandeVetpointController extends Controller
{
    /** Un envoi plus récent que cela est peut-être encore en route : on attend */
    private const int ENVOI_EN_COURS_SECONDES = 60;

    /** Adresses de livraison du compte Vetpoint de la clinique de livraison. */
    public function adresses(int $commande): JsonResponse
    {
        $commande = CommandeFournisseurController::commandeDeLOrganisation($commande);
        $this->authorize('update', $commande);

        return response()->json(['data' => $this->appel(fn () => $this->client($commande)->adressesLivraison())]);
    }

    public function envoyer(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = CommandeFournisseurController::commandeDeLOrganisation($commande);
        $this->authorize('update', $commande);

        $choix = $request->validate([
            'adresse_id' => ['required', 'string', 'max:40'],
            'livraison' => ['required', 'in:standard,express'],
            'date_souhaitee' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'adresse_id.required' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
            'livraison.required' => 'Choisissez une livraison standard ou express.',
            'livraison.in' => 'Choisissez une livraison standard ou express.',
            'date_souhaitee.date_format' => 'Donnez la date souhaitée au format AAAA-MM-JJ.',
        ]);
        $express = $choix['livraison'] === 'express';
        $date = $choix['date_souhaitee'] ?? null;
        if ($date !== null && $express) {
            throw ValidationException::withMessages(['date_souhaitee' => 'Pas de date souhaitée en express : la livraison est la plus rapide possible.']);
        }
        $clinique = Clinique::withoutGlobalScopes()->findOrFail($commande->clinique_id);
        if ($date !== null && $date <= aujourdhui($clinique)->toDateString()) {
            throw ValidationException::withMessages(['date_souhaitee' => 'La date de livraison souhaitée doit être dans le futur.']);
        }

        if ($commande->statut !== StatutCommandeFournisseur::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
        }
        $lignes = $commande->lignes()->with('produit.vetpoint')->get();
        if ($lignes->isEmpty()) {
            throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une ligne avant d’envoyer.']);
        }
        $nonRelies = $lignes->filter(fn (CommandeFournisseurLigne $ligne): bool => $ligne->produit?->vetpoint === null)
            ->map(fn (CommandeFournisseurLigne $ligne): string => (string) $ligne->produit?->nom)->unique()->values();
        if ($nonRelies->isNotEmpty()) {
            throw ValidationException::withMessages(['lignes' => 'Pas encore relié à Vetpoint : '.$nonRelies->implode(', ').'. '
                .($nonRelies->count() > 1 ? 'Reliez-les' : 'Reliez-le').' depuis le stock, ou envoyez la commande autrement.']);
        }

        $client = $this->client($commande);
        $adresses = $this->appel(fn () => $client->adressesLivraison());
        if (! collect($adresses)->contains('id', $choix['adresse_id'])) {
            throw ValidationException::withMessages(['adresse_id' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.']);
        }

        // Sous verrou : un seul envoi à la fois ; un envoi dont la réponse s'est
        // perdue est d'abord cherché chez Vetpoint
        $reprise = DB::transaction(function () use ($commande, $choix, $date): ?bool {
            $verrouillee = CommandeFournisseur::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut !== StatutCommandeFournisseur::Brouillon) {
                throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
            }
            $precedent = $verrouillee->vetpoint_envoi_le;
            if ($precedent !== null && $precedent->greaterThan(now()->subSeconds(self::ENVOI_EN_COURS_SECONDES))) {
                return null;
            }
            $verrouillee->forceFill([
                'vetpoint_envoi_le' => now(),
                'vetpoint_adresse_id' => $choix['adresse_id'],
                'vetpoint_livraison' => $choix['livraison'],
                'vetpoint_date_souhaitee' => $date,
            ])->save();

            return $precedent !== null;
        });
        abort_if($reprise === null, 409, 'Cette commande part déjà chez Vetpoint : patientez quelques secondes.');

        $note = 'Veterinary '.$commande->numero;
        $liberer = fn () => CommandeFournisseur::query()->whereKey($commande->id)->update(['vetpoint_envoi_le' => null]);
        try {
            $numero = $reprise ? $this->retrouver($client, $note, $commande) : null;
            $numero ??= $client->commander(array_filter([
                'shippingAddressId' => $choix['adresse_id'],
                'deliveryType' => $express ? 'EXPRESS' : 'STANDARD',
                'desiredDeliveryDate' => $date !== null ? $date.'T00:00:00' : null,
                'orderItems' => $lignes->map(fn (CommandeFournisseurLigne $ligne): array => [
                    'productId' => (string) $ligne->produit?->vetpoint?->vetpoint_id,
                    'quantity' => (int) $ligne->quantite,
                    'orderNote' => $note,
                ])->values()->all(),
            ], fn ($valeur) => $valeur !== null));
        } catch (VetpointRefus $e) {
            $liberer();
            throw ValidationException::withMessages(['vetpoint' => $e->getMessage()]);
        } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite $e) {
            // Refusé avant tout traitement : rien n'est parti
            $liberer();
            $this->refuser($e);
        } catch (VetpointIndisponible) {
            // On ne sait pas : la commande est peut-être partie, le prochain essai la cherchera
            abort(503, 'Vetpoint ne répond pas : la commande est peut-être partie. Réessayez dans une minute, elle ne partira pas deux fois.');
        }

        // Relue : les choix posés sous verrou (adresse, livraison, date)
        $commande->refresh()->forceFill([
            'statut' => StatutCommandeFournisseur::Envoyee,
            'envoyee_le' => now(),
            'vetpoint_commande_id' => $numero,
            'vetpoint_envoi_le' => null,
        ])->save();

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    /** La commande chez Vetpoint : ses lignes avec prix réels et péremptions, dès que Vetpoint les donne. */
    public function detail(int $commande): JsonResponse
    {
        $commande = CommandeFournisseurController::commandeDeLOrganisation($commande);
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
     * produit ↔ Vetpoint.
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{ligne_id: int|null, produit_vetpoint: string, quantite: int|null, prix_unitaire: float|null, prix_total: float|null, peremption: string|null}>
     */
    public static function lignesDetaillees(CommandeFournisseur $commande, array $detail): array
    {
        $parVetpoint = $commande->lignes()->with('produit.vetpoint')->get()
            ->keyBy(fn (CommandeFournisseurLigne $ligne): string => (string) $ligne->produit?->vetpoint?->vetpoint_id);
        $elements = is_array($detail['orderItems'] ?? null) ? $detail['orderItems'] : [];

        return array_values(array_map(function (array $element) use ($parVetpoint): array {
            $produit = is_scalar($element['productId'] ?? null) ? (string) $element['productId'] : '';
            $peremption = is_string($element['expirationDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $element['expirationDate']) === 1
                ? substr($element['expirationDate'], 0, 10)
                : (is_scalar($element['expirationDate'] ?? null) ? (string) $element['expirationDate'] : null);

            return [
                'ligne_id' => $parVetpoint->get($produit)?->id,
                'produit_vetpoint' => $produit,
                'quantite' => is_numeric($element['quantity'] ?? null) ? (int) $element['quantity'] : null,
                'prix_unitaire' => ClientVetpoint::nombre($element['unitPrice'] ?? null),
                'prix_total' => ClientVetpoint::nombre($element['totalPrice'] ?? null),
                'peremption' => $peremption,
            ];
        }, array_filter($elements, 'is_array')));
    }

    /** Le client Vetpoint de la clinique de livraison, ou un refus clair. */
    public static function clientDe(int $cliniqueId): ClientVetpoint
    {
        $connexion = ConnexionVetpoint::query()->where('clinique_id', $cliniqueId)->first();
        abort_if($connexion === null, 409, 'Vetpoint n’est pas relié à la clinique de livraison : collez sa clé dans Réglages, Fournisseurs.');
        $cle = $connexion->cleEnClair();
        abort_if($cle === null, 409, 'La clé Vetpoint de la clinique est illisible : collez-la à nouveau dans Réglages, Fournisseurs.');

        return ClientVetpoint::pour($cle);
    }

    private function client(CommandeFournisseur $commande): ClientVetpoint
    {
        return self::clientDe($commande->clinique_id);
    }

    /** Une commande déjà arrivée chez Vetpoint pour cet envoi (réponse perdue) : son numéro. */
    private function retrouver(ClientVetpoint $client, string $note, CommandeFournisseur $commande): ?string
    {
        $depuis = ($commande->vetpoint_envoi_le ?? now())->copy()->subDay()->toDateString();
        foreach ($client->commandes($depuis, now()->addDay()->toDateString()) as $existante) {
            $elements = is_array($existante['orderItems'] ?? null) ? $existante['orderItems'] : [];
            $notre = collect($elements)->contains(fn (mixed $element): bool => is_array($element) && ($element['orderNote'] ?? null) === $note);
            if ($notre && is_scalar($existante['orderId'] ?? null)) {
                return (string) $existante['orderId'];
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $appel
     * @return T
     */
    private function appel(Closure $appel): mixed
    {
        try {
            return $appel();
        } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite|VetpointIndisponible $e) {
            $this->refuser($e);
        }
    }

    private function refuser(\Throwable $e): never
    {
        match (true) {
            $e instanceof VetpointCleRefusee => abort(409, 'Vetpoint refuse la clé de la clinique : elle a peut-être été renouvelée. Générez-en une et collez-la dans Réglages, Fournisseurs.'),
            $e instanceof VetpointCgv => abort(409, 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans le profil Vetpoint de la clinique, puis réessayez.'),
            $e instanceof VetpointLimite => abort(429, 'Vetpoint limite les appels : réessayez dans une minute.'),
            default => abort(503, 'Vetpoint ne répond pas : réessayez dans un moment.'),
        };
    }
}
