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
            if (is_array($fournisseur) && isset($fournisseur['supplierId'])) {
                $fournisseurs[(string) $fournisseur['supplierId']] = (string) ($fournisseur['name'] ?? '');
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
                if (is_array($ligne) && ($ligne['status'] ?? null) === 'OK' && isset($ligne['productId'], $ligne['totalPrice']) && is_scalar($ligne['productId']) && is_numeric($ligne['totalPrice'])) {
                    $prix[(string) $ligne['productId']] = (float) $ligne['totalPrice'];
                }
            }
        }

        return $prix;
    }

    /**
     * @param  callable(PendingRequest): Response  $appel
     * @return array<array-key, mixed>
     */
    private function appeler(callable $appel, bool $introuvableVide = false): array
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
        if ($reponse->status() === 404 && $introuvableVide) {
            return [];
        }
        if ($reponse->status() === 429) {
            throw new VetpointLimite;
        }
        if ($reponse->failed() || ! is_array($reponse->json())) {
            throw new VetpointIndisponible;
        }

        return $reponse->json();
    }
}
