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
        $texte = match (true) {
            is_scalar($corps['errorMessage'] ?? null) => trim((string) $corps['errorMessage']),
            is_array($reponse->json()) => '',
            default => trim((string) $reponse->body()),
        };
        // Le code arrive dans « errorTyoe » (sic, OpenAPI ; « errorType » s'il
        // est corrigé), sinon en tête du message :
        // un nombre au milieu du texte (une année) n'en est pas un
        $type = is_scalar($corps['errorTyoe'] ?? $corps['errorType'] ?? null) ? trim((string) ($corps['errorTyoe'] ?? $corps['errorType'])) : '';
        $code = match (true) {
            preg_match('/^\d{4}$/', $type) === 1 => $type,
            preg_match('/^(\d{4})\b/', $texte, $trouve) === 1 => $trouve[1],
            default => null,
        };

        // « Das Produkt 'xyz' hat eine Mindestbestellmenge von x » : le nom
        // va jusqu'à la dernière apostrophe (« Hill's »)
        if ($code === '1122' || preg_match('/Mindestbestellmenge/i', $texte) === 1) {
            return new self(preg_match("/Produkt\\s+'(.+)'\\s+hat\\s+eine\\s+Mindestbestellmenge\\s+von\\s+(\\d+)/iu", $texte, $m) === 1
                ? "Le produit « {$m[1]} » se commande par {$m[2]} au moins."
                : 'Un produit est en dessous de sa quantité minimale de commande chez Vetpoint.');
        }
        if ($code !== null && isset(self::MESSAGES[$code])) {
            return new self(self::MESSAGES[$code]);
        }
        // 400 : « Product not found » (OpenAPI) ; un autre message est rendu tel quel
        if (($reponse->status() === 400 && $texte === '') || strcasecmp($texte, 'Product not found') === 0) {
            return new self('Un produit de la commande est introuvable chez Vetpoint : vérifiez ses liens.');
        }

        // Une page HTML (un proxy) n'est pas un message de Vetpoint
        $html = preg_match('/^\s*</', $texte) === 1;

        return new self($texte === '' || $html ? 'Vetpoint a refusé la commande.' : 'Vetpoint a refusé la commande. Son message : « '.mb_substr($texte, 0, 300).' ».');
    }
}
