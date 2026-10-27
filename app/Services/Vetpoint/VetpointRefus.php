<?php

namespace App\Services\Vetpoint;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Vetpoint refuse une commande (400, 404, 422) : rien n'est parti. Le message
 * est en français, d'après les codes de l'OpenAPI de Vetpoint (28.10.2026).
 */
class VetpointRefus extends RuntimeException
{
    private const array MESSAGES = [
        '4001' => 'Choisissez une adresse de livraison.',
        '4002' => 'Cette adresse de livraison n’est pas valable chez Vetpoint.',
        '4003' => 'Les médicaments ne se livrent qu’à une adresse enregistrée chez Vetpoint.',
        '1063' => 'La date de livraison souhaitée doit être dans le futur.',
        '1064' => 'Pas de date souhaitée en express : la livraison est la plus rapide possible.',
        '1065' => 'L’adresse de livraison est incomplète chez Vetpoint.',
        '1066' => 'L’adresse de livraison est incomplète chez Vetpoint.',
        '1067' => 'L’adresse de livraison est incomplète chez Vetpoint.',
        '1068' => 'L’adresse de livraison est incomplète chez Vetpoint.',
        '5000' => 'Vetpoint a refusé la commande sans dire pourquoi : contactez Vetpoint.',
    ];

    public static function depuis(Response $reponse): self
    {
        $corps = is_array($reponse->json()) ? $reponse->json() : [];
        $texte = is_scalar($corps['errorMessage'] ?? null) ? trim((string) $corps['errorMessage']) : trim((string) $reponse->body());
        // Le code arrive dans « errorTyoe » (sic) ou dans le message
        $type = is_scalar($corps['errorTyoe'] ?? null) ? (string) $corps['errorTyoe'] : '';
        $code = preg_match('/\b(\d{4})\b/', $type.' '.$texte, $trouve) === 1 ? $trouve[1] : null;

        // « Das Produkt 'xyz' hat eine Mindestbestellmenge von x »
        if ($code === '1122' || preg_match('/Mindestbestellmenge/i', $texte) === 1) {
            return new self(preg_match("/Produkt\\s+'(.+?)'.*?Mindestbestellmenge\\s+von\\s+(\\d+)/iu", $texte, $m) === 1
                ? "Le produit « {$m[1]} » se commande par {$m[2]} au moins."
                : 'Un produit est en dessous de sa quantité minimale de commande chez Vetpoint.');
        }
        if ($code !== null && isset(self::MESSAGES[$code])) {
            return new self(self::MESSAGES[$code]);
        }
        if ($reponse->status() === 400 || strcasecmp($texte, 'Product not found') === 0) {
            return new self('Un produit de la commande est introuvable chez Vetpoint : vérifiez ses liens.');
        }

        return new self($texte === '' ? 'Vetpoint a refusé la commande.' : 'Vetpoint a refusé la commande. Son message : « '.mb_substr($texte, 0, 300).' ».');
    }
}
