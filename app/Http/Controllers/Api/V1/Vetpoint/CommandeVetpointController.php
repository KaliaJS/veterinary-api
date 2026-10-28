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
use App\Services\Vetpoint\VetpointIncertain;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use App\Services\Vetpoint\VetpointRefus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'adresse_id.string' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
            'adresse_id.max' => 'Choisissez une adresse de livraison du compte Vetpoint de la clinique.',
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
        $reprise = DB::transaction(function () use ($commande): ?bool {
            $verrouillee = CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut !== StatutCommandeFournisseur::Brouillon) {
                throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
            }
            // La clé et les adresses lues sont celles de la clinique de
            // livraison d'avant le verrou : changée entre-temps, rien ne part
            abort_if($verrouillee->clinique_id !== $commande->clinique_id, 409, 'La clinique de livraison a changé pendant l’envoi : renvoyez la commande.');
            $precedent = $verrouillee->vetpoint_envoi_le;
            if ($precedent !== null && $precedent->greaterThan(now()->subSeconds(self::ENVOI_EN_COURS_SECONDES))) {
                return null;
            }
            // Les choix ne s'écrivent qu'au moment d'envoyer : une commande
            // retrouvée garde ceux avec lesquels elle est partie
            $verrouillee->forceFill(['vetpoint_envoi_le' => now()])->save();

            return $precedent !== null;
        });
        abort_if($reprise === null, 409, 'Cette commande part déjà chez Vetpoint : patientez quelques secondes.');

        // Relues une fois l'envoi posé (les lignes ne se modifient plus) :
        // ce qui part chez Vetpoint est ce qui est gardé, même si un autre
        // onglet les a changées pendant la lecture des adresses
        $lignes = $commande->lignes()->with('produit.vetpoint')->get();
        if ($lignes->isEmpty() || $lignes->contains(fn (CommandeFournisseurLigne $ligne): bool => $ligne->produit?->vetpoint === null)) {
            // Une reprise reste en suspens : la commande est peut-être partie
            if (! $reprise) {
                CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->update(['vetpoint_envoi_le' => null]);
            }
            throw ValidationException::withMessages(['lignes' => 'Les lignes ont changé pendant l’envoi : vérifiez-les, puis renvoyez.']);
        }

        $note = 'Veterinary '.$commande->numero;
        $liberer = fn () => CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->update(['vetpoint_envoi_le' => null]);
        $numero = null;
        if ($reprise) {
            // La commande est peut-être partie : sans recherche aboutie, elle
            // reste en suspens et ne repart jamais (le prochain essai cherche)
            try {
                $numero = $this->retrouver($client, $note, $commande);
            } catch (VetpointIndisponible) {
                abort(503, 'Vetpoint ne répond pas : la commande est peut-être partie. Réessayez dans une minute : elle sera d’abord cherchée chez Vetpoint.');
            } catch (VetpointIncertain) {
                abort(409, 'Vetpoint ne dit pas si cette commande est déjà partie : vérifiez sur vetpoint.ch, puis abandonnez l’envoi par Vetpoint si elle n’y est pas.');
            } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite $e) {
                $this->refuser($e);
            }
        }
        try {
            if ($numero === null) {
                CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->update([
                    'vetpoint_adresse_id' => $choix['adresse_id'],
                    'vetpoint_livraison' => $choix['livraison'],
                    'vetpoint_date_souhaitee' => $date,
                ]);
                // Le produit Vetpoint de chaque ligne, gardé : un article délié
                // avant la réception garde son prix réel
                $lignes->each(fn (CommandeFournisseurLigne $ligne) => $ligne->forceFill(['vetpoint_produit_id' => $ligne->produit?->vetpoint?->vetpoint_id])->save());
            }
            $numero ??= $client->commander(array_filter([
                'shippingAddressId' => $choix['adresse_id'],
                'deliveryType' => $express ? 'EXPRESS' : 'STANDARD',
                // Midi à la clinique, décalage compris : le jour reste le même
                // quel que soit le fuseau où Vetpoint le lit
                'desiredDeliveryDate' => $date !== null ? CarbonImmutable::parse($date.' 12:00', $clinique->fuseau_horaire ?: 'Europe/Zurich')->toIso8601String() : null,
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
            // L'envoi lui-même refusé avant tout traitement : rien n'est parti
            $liberer();
            $this->refuser($e);
        } catch (VetpointIndisponible) {
            // On ne sait pas : la commande est peut-être partie, le prochain essai la cherchera
            abort(503, 'Vetpoint ne répond pas : la commande est peut-être partie. Réessayez dans une minute : elle sera d’abord cherchée chez Vetpoint.');
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
        $commande = CommandeFournisseurController::commandeDeLOrganisation($commande);
        $this->authorize('update', $commande);

        $commande = DB::transaction(function () use ($commande): CommandeFournisseur {
            $verrouillee = CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut !== StatutCommandeFournisseur::Brouillon || $verrouillee->vetpoint_envoi_le === null || $verrouillee->vetpoint_commande_id !== null) {
                throw ValidationException::withMessages(['statut' => 'Aucun envoi par Vetpoint en suspens pour cette commande.']);
            }
            abort_if($verrouillee->vetpoint_envoi_le->greaterThan(now()->subSeconds(self::ENVOI_EN_COURS_SECONDES)), 409, 'Cette commande part peut-être en ce moment chez Vetpoint : patientez une minute.');
            $verrouillee->forceFill(['vetpoint_envoi_le' => null, 'vetpoint_adresse_id' => null, 'vetpoint_livraison' => null, 'vetpoint_date_souhaitee' => null])->save();

            return $verrouillee;
        });

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
     * Le client Vetpoint de la clinique de livraison, ou un refus clair. Une
     * clinique sœur commande avec sa propre clé : la commande, déjà bornée à
     * l'organisation, dit laquelle (hors scope de la clinique du jeton).
     */
    public static function clientDe(int $cliniqueId): ClientVetpoint
    {
        $connexion = ConnexionVetpoint::withoutGlobalScopes()->where('clinique_id', $cliniqueId)->first();
        abort_if($connexion === null, 409, 'Vetpoint n’est pas relié à la clinique de livraison : collez sa clé dans Réglages, Fournisseurs, depuis cette clinique.');
        $cle = $connexion->cleEnClair();
        abort_if($cle === null, 409, 'La clé Vetpoint de la clinique est illisible : collez-la à nouveau dans Réglages, Fournisseurs.');

        return ClientVetpoint::pour($cle);
    }

    private function client(CommandeFournisseur $commande): ClientVetpoint
    {
        return self::clientDe($commande->clinique_id);
    }

    /**
     * Une commande déjà arrivée chez Vetpoint pour cet envoi (réponse perdue) :
     * son numéro. Cherchée depuis la création du brouillon (aucun envoi n'est
     * plus ancien, même après plusieurs essais), par la note de ses lignes
     * seulement (rendue par GET /v1/order, OpenAPI) : une commande aux mêmes
     * produits n'est jamais devinée, ce serait peut-être une autre (passée
     * sur vetpoint.ch, ou une autre de nos commandes). Un numéro déjà gardé
     * par une commande n'est jamais repris.
     */
    private function retrouver(ClientVetpoint $client, string $note, CommandeFournisseur $commande): ?string
    {
        $creee = CarbonImmutable::parse($commande->created_at ?? now());
        $commandes = $client->commandes($creee->subDay()->toDateString(), now()->addDay()->toDateString());
        // Une note rendue avec des espaces ou une autre casse reste la nôtre
        $normaliser = fn (mixed $texte): string => is_string($texte) ? mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texte))) : '';
        $cherchee = $normaliser($note);
        $notes = [];
        foreach ($commandes as $existante) {
            $numero = is_string($existante['orderId'] ?? null) || is_int($existante['orderId'] ?? null) ? trim((string) $existante['orderId']) : '';
            $elements = array_filter(is_array($existante['orderItems'] ?? null) ? $existante['orderItems'] : [], 'is_array');
            foreach ($elements as $element) {
                if ($normaliser($element['orderNote'] ?? null) !== '') {
                    $notes[] = $element['orderNote'];
                }
            }
            if (! collect($elements)->contains(fn (array $element): bool => $normaliser($element['orderNote'] ?? null) === $cherchee)) {
                continue;
            }
            // La nôtre est là, sans numéro lisible : partie, mais on ne sait
            // pas sous quel numéro. Surtout pas de renvoi
            if ($numero === '') {
                throw new VetpointIncertain;
            }
            if (! CommandeFournisseur::withoutGlobalScopes()->where('vetpoint_commande_id', $numero)->exists()) {
                return $numero;
            }
        }
        // Des commandes, mais aucune note : Vetpoint ne les rend peut-être pas,
        // et la nôtre est peut-être là. On ne renvoie pas à l'aveugle
        if ($commandes !== [] && $notes === []) {
            throw new VetpointIncertain;
        }
        // Aucune note ne correspond, mais une commande aux mêmes produits et
        // quantités est là (note tronquée ou rangée ailleurs ?) : on ne
        // l'adopte pas, on ne renvoie pas non plus, on fait vérifier
        $attendu = $commande->lignes()->with('produit.vetpoint')->get()
            ->map(fn (CommandeFournisseurLigne $ligne): string => ($ligne->vetpoint_produit_id ?? $ligne->produit?->vetpoint?->vetpoint_id).' × '.(int) $ligne->quantite)
            ->sort()->values()->all();
        foreach ($commandes as $existante) {
            $elements = collect(is_array($existante['orderItems'] ?? null) ? $existante['orderItems'] : [])->filter(fn (mixed $e): bool => is_array($e));
            $numero = is_string($existante['orderId'] ?? null) || is_int($existante['orderId'] ?? null) ? trim((string) $existante['orderId']) : '';
            // Déjà gardée par une commande, ou portant la note entière d'une
            // autre de nos commandes : sûrement pas la nôtre
            $autreDesNotres = $elements->contains(function (array $e) use ($normaliser, $commande): bool {
                if (preg_match('/^veterinary ([a-z]{2}-\d{4}-\d+)$/u', $normaliser($e['orderNote'] ?? null), $m) !== 1) {
                    return false;
                }

                // Une note entière qui nomme une autre commande existante (pas tronquée)
                return mb_strtoupper($m[1]) !== $commande->numero
                    && CommandeFournisseur::query()->dansLOrganisation()->where('numero', mb_strtoupper($m[1]))->exists();
            });
            if ($autreDesNotres || ($numero !== '' && CommandeFournisseur::withoutGlobalScopes()->where('vetpoint_commande_id', $numero)->exists())) {
                continue;
            }
            // Passée avant la création du brouillon (trois heures de marge pour
            // une heure rendue sans fuseau) : ce n'est pas la nôtre
            $passee = is_string($existante['orderDate'] ?? null) ? rescue(fn () => CarbonImmutable::parse($existante['orderDate'], 'Europe/Zurich'), null, false) : null;
            if ($passee !== null && $passee->lessThan($creee->subHours(3))) {
                continue;
            }
            $recu = $elements
                ->map(fn (array $e): string => (is_scalar($e['productId'] ?? null) ? (string) $e['productId'] : '').' × '.(is_numeric($e['quantity'] ?? null) ? (int) $e['quantity'] : -1))
                ->sort()->values()->all();
            if ($recu === $attendu) {
                throw new VetpointIncertain;
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
