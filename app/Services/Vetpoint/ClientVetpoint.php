<?php

namespace App\Services\Vetpoint;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Interface REST de Vetpoint pour les logiciels de cabinet (TAPSW,
 * 27.10.2026), au nom d'une clinique : sa clé, ses prix, ses adresses.
 * Les réponses réelles du serveur de test diffèrent de la documentation :
 * `{"products": [...]}` ou `{}` pour une recherche, `{"suppliers": [...]}`
 * pour les fournisseurs. La recherche ne donne que le prix catalogue ; le
 * prix de la clinique vient de `calculatePrices` (20 produits au plus).
 * Toute réponse de forme inattendue compte comme une panne (jamais un 500) ;
 * un numéro Swissmedic inconnu (404) ne donne aucun résultat.
 */
class ClientVetpoint
{
    /** Produits par calcul de prix, d'après la documentation de Vetpoint */
    public const int PRIX_PAR_APPEL = 20;

    public function __construct(private readonly string $cle) {}

    public static function pour(string $cle): self
    {
        return new self($cle);
    }

    /** @return array<string, string> identifiant => nom */
    public function fournisseurs(): array
    {
        $liste = $this->appeler(fn (PendingRequest $http): Response => $http->get('/v1/supplier'))['suppliers'] ?? [];
        if (! is_array($liste)) {
            throw new VetpointIndisponible;
        }
        $fournisseurs = [];
        foreach ($liste as $fournisseur) {
            // Un fournisseur aux champs de forme inattendue est ignoré
            if (is_array($fournisseur) && isset($fournisseur['supplierId'], $fournisseur['name']) && is_scalar($fournisseur['supplierId']) && is_scalar($fournisseur['name'])) {
                $fournisseurs[(string) $fournisseur['supplierId']] = (string) $fournisseur['name'];
            }
        }

        return $fournisseurs;
    }

    /**
     * Par numéro Swissmedic (999999-99-999) ou par texte.
     *
     * @return list<array<string, mixed>>
     */
    public function rechercher(string $texte): array
    {
        $parSwissmedic = preg_match('/^\d{5,6}-\d{2}-\d{3}$/', $texte) === 1;
        $chemin = $parSwissmedic
            ? '/v1/product/swissmedic='.rawurlencode($texte)
            : '/v1/product/query='.rawurlencode($texte);
        // 404 : « Product not found » pour un numéro Swissmedic inconnu ; sur une
        // recherche par nom, c'est une adresse fausse, donc une panne
        $reponse = $this->appeler(fn (PendingRequest $http): Response => $http->get($chemin), introuvableVide: $parSwissmedic);
        $produits = array_key_exists('products', $reponse) ? $reponse['products'] : (array_is_list($reponse) ? $reponse : []);
        if (! is_array($produits)) {
            throw new VetpointIndisponible;
        }

        return array_values(array_filter($produits, fn (mixed $produit): bool => is_array($produit) && isset($produit['productId']) && is_scalar($produit['productId'])));
    }

    /**
     * Prix de la clinique pour une pièce, par produit (ceux que Vetpoint
     * calcule : statut OK).
     *
     * @param  list<string>  $identifiants
     * @return array<string, float>
     */
    public function prixUnitaires(array $identifiants): array
    {
        $prix = [];
        foreach (array_chunk($identifiants, self::PRIX_PAR_APPEL) as $lot) {
            $lignes = $this->appeler(fn (PendingRequest $http): Response => $http->post('/v1/product/calculatePrices', array_map(
                fn (string $identifiant): array => ['productId' => $identifiant, 'quantity' => 1],
                $lot,
            )));
            foreach ($lignes as $ligne) {
                $montant = is_array($ligne) ? self::nombre($ligne['totalPrice'] ?? null) : null;
                if ($montant !== null && ($ligne['status'] ?? null) === 'OK' && isset($ligne['productId']) && is_scalar($ligne['productId'])) {
                    $prix[(string) $ligne['productId']] = $montant;
                }
            }
        }

        return $prix;
    }

    /**
     * Adresses de livraison du compte Vetpoint de la clinique (les seules
     * permises pour les médicaments).
     *
     * @return list<array{id: string, libelle: string, par_defaut: bool}>
     */
    public function adressesLivraison(): array
    {
        $liste = $this->appeler(fn (PendingRequest $http): Response => $http->get('/v1/shipping-address'));
        $adresses = [];
        foreach (array_is_list($liste) ? $liste : [] as $adresse) {
            if (! is_array($adresse) || ! isset($adresse['shippingAddressId']) || ! is_scalar($adresse['shippingAddressId'])) {
                continue;
            }
            $champ = fn (string $nom): string => is_scalar($adresse[$nom] ?? null) ? trim((string) $adresse[$nom]) : '';
            $nom = $champ('company') !== '' ? $champ('company') : trim($champ('firstName').' '.$champ('lastName'));
            $adresses[] = [
                'id' => (string) $adresse['shippingAddressId'],
                'libelle' => implode(', ', array_filter([$nom, $champ('address1'), $champ('address2'), trim($champ('postalCode').' '.$champ('city'))])),
                'par_defaut' => ($adresse['defaultShippingAddress'] ?? false) === true,
            ];
        }

        return $adresses;
    }

    /**
     * Envoie une commande, en une fois (tout ou rien chez Vetpoint). Rend son
     * numéro Vetpoint. Un refus (400, 404, 422) : VetpointRefus, rien n'est
     * parti ; une panne : on ne sait pas, à retrouver avant de renvoyer.
     *
     * @param  array<string, mixed>  $commande
     */
    public function commander(array $commande): string
    {
        $reponse = $this->requete(fn (PendingRequest $http): Response => $http->post('/v1/order', $commande));
        if (in_array($reponse->status(), [400, 404, 422], true)) {
            throw VetpointRefus::depuis($reponse);
        }
        if ($reponse->failed()) {
            throw new VetpointIndisponible;
        }
        // « Order ID » : en texte, en chaîne JSON ou dans `{orderId}`. Toute
        // autre forme ({"id":…}, [], null) n'en donne pas : la commande est
        // peut-être partie, l'essai suivant la cherche par sa note
        $corps = trim($reponse->body());
        $json = json_decode($corps, true);
        $numero = match (true) {
            json_last_error() !== JSON_ERROR_NONE => $corps,
            is_string($json) || is_int($json) => (string) $json,
            is_array($json) && (is_string($json['orderId'] ?? null) || is_int($json['orderId'] ?? null)) => (string) $json['orderId'],
            default => '',
        };
        $numero = trim($numero);
        if ($numero === '' || mb_strlen($numero) > 40 || preg_match('/[\p{Cc}\s"{}\[\]]/u', $numero) === 1) {
            throw new VetpointIndisponible;
        }

        return $numero;
    }

    /**
     * Commandes passées entre deux dates (incluses).
     *
     * @return list<array<string, mixed>>
     */
    public function commandes(string $du, string $au): array
    {
        $liste = $this->appeler(fn (PendingRequest $http): Response => $http->get('/v1/order', ['fromDate' => $du, 'thruDate' => $au]));

        // Sans commande : `{}` plutôt qu'une liste vide
        return array_values(array_filter(array_is_list($liste) ? $liste : [], 'is_array'));
    }

    /** @return array<string, mixed> */
    public function commande(string $numero): array
    {
        return $this->appeler(fn (PendingRequest $http): Response => $http->get('/v1/order/'.rawurlencode($numero)));
    }

    /** Premier jour du mois de péremption (format des lots), d'une date de Vetpoint de forme connue. */
    public static function peremption(mixed $valeur): ?string
    {
        if (! is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $valeur = trim($valeur);
        foreach (['/^(\d{4})-(\d{2})(?:-\d{2})?/' => [1, 2], '/^\d{2}\.(\d{2})\.(\d{4})$/' => [2, 1], '/^(\d{2})\.(\d{4})$/' => [2, 1], '/^(\d{2})\/(\d{4})$/' => [2, 1]] as $forme => [$annee, $mois]) {
            if (preg_match($forme, $valeur, $m) === 1 && checkdate((int) $m[$mois], 1, (int) $m[$annee])) {
                return sprintf('%04d-%02d-01', (int) $m[$annee], (int) $m[$mois]);
            }
        }

        return null;
    }

    /** Un montant lisible et fini (1e400 ou « n/a » : aucun). */
    public static function nombre(mixed $valeur): ?float
    {
        return is_numeric($valeur) && is_finite((float) $valeur) ? (float) $valeur : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $appel
     * @return array<array-key, mixed>
     */
    private function appeler(callable $appel, bool $introuvableVide = false): array
    {
        $reponse = $this->requete($appel);

        // « Product not found » seulement (OpenAPI) : une page 404, un « Not
        // Found » générique ou un message d'une autre forme sont une panne
        $message = is_array($reponse->json()) ? ($reponse->json()['errorMessage'] ?? null) : null;
        if ($reponse->status() === 404 && $introuvableVide && is_string($message) && strcasecmp(trim($message), 'Product not found') === 0) {
            return [];
        }
        if ($reponse->failed() || ! is_array($reponse->json())) {
            throw new VetpointIndisponible;
        }

        return $reponse->json();
    }

    /**
     * L'appel, avec les refus communs : clé (401), conditions (403), limite
     * (429), serveur injoignable.
     *
     * @param  callable(PendingRequest): Response  $appel
     */
    private function requete(callable $appel): Response
    {
        try {
            $reponse = $appel(Http::baseUrl(rtrim((string) config('services.vetpoint.url'), '/'))
                ->withHeaders(['X-Api-Key' => $this->cle])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15));
        } catch (ConnectionException) {
            throw new VetpointIndisponible;
        }

        if ($reponse->status() === 401) {
            throw new VetpointCleRefusee;
        }
        if ($reponse->status() === 403) {
            throw new VetpointCgv;
        }
        if ($reponse->status() === 429) {
            throw new VetpointLimite;
        }

        return $reponse;
    }
}
