<?php

namespace App\Support;

use App\Models\Facture;

/**
 * La QR-facture suisse d'une facture (01.11.2026, choix de Jim : comme
 * pour le food truck, aux normes SIX « Swiss Implementation Guidelines
 * QR-bill » 2.3). Le serveur donne ce que la section paiement imprime et ce
 * que contient le code QR : le compte (IBAN ou QR-IBAN), le créancier (la
 * société, à l'adresse de la clinique qui facture), le débiteur (le client
 * de la facture), le montant restant à payer, la référence et le message.
 *
 * La référence permet à la banque de rattacher chaque paiement à sa
 * facture :
 * - avec un QR-IBAN (IID 30000 à 31999) : référence QR (QRR), 27 chiffres,
 *   le dernier calculé modulo 10 récursif ;
 * - avec un IBAN ordinaire : référence du créancier ISO 11649 (SCOR),
 *   « RF » + 2 chiffres de contrôle (modulo 97) + le numéro de facture.
 */
class QrFacture
{
    private const array MODULO_10 = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    public static function normaliserIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /** La clé de contrôle d'un IBAN (ISO 13616, modulo 97) */
    public static function ibanValide(string $iban): bool
    {
        $iban = self::normaliserIban($iban);

        return strlen($iban) >= 15 && self::modulo97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    /** Un QR-IBAN : l'IID (positions 5 à 9) entre 30000 et 31999 */
    public static function estQrIban(string $iban): bool
    {
        $iid = (int) substr(self::normaliserIban($iban), 4, 5);

        return $iid >= 30000 && $iid <= 31999;
    }

    /** La référence QR de 27 chiffres : 26 chiffres, puis la clé modulo 10 récursif */
    public static function referenceQr(string $chiffres): string
    {
        $base = str_pad(substr((string) preg_replace('/\D/', '', $chiffres), -26), 26, '0', STR_PAD_LEFT);
        $report = 0;
        foreach (str_split($base) as $chiffre) {
            $report = self::MODULO_10[($report + (int) $chiffre) % 10];
        }

        return $base.((10 - $report) % 10);
    }

    /** La référence du créancier ISO 11649 : « RF », 2 chiffres de contrôle, jusqu'à 21 caractères */
    public static function referenceCreancier(string $texte): string
    {
        $corps = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($texte)), 0, 21);
        $controle = 98 - self::modulo97($corps.'RF00');

        return 'RF'.str_pad((string) $controle, 2, '0', STR_PAD_LEFT).$corps;
    }

    /** Lettres en nombres (A = 10 … Z = 35), puis le reste modulo 97, par morceaux */
    private static function modulo97(string $texte): int
    {
        $nombre = '';
        foreach (str_split($texte) as $c) {
            $nombre .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }
        $reste = 0;
        foreach (str_split($nombre, 7) as $morceau) {
            // Le reste précédent et sept chiffres : neuf chiffres au plus, un entier sûr
            $reste = intval($reste.$morceau) % 97;
        }

        return $reste;
    }

    /**
     * Ce que la section paiement imprime pour cette facture, ou null : déjà
     * payée, annulée, rien à payer, ou la société n'a pas donné son IBAN.
     *
     * @return array<string, mixed>|null
     */
    public static function pour(Facture $facture): ?array
    {
        $clinique = $facture->clinique;
        $societe = $clinique?->societe;
        $reste = round($facture->resteAPayer(), 2);
        if ($societe === null || blank($societe->iban) || $facture->statut->value !== 'due' || $reste <= 0) {
            return null;
        }
        $iban = self::normaliserIban($societe->iban);
        $estQr = self::estQrIban($iban);
        $client = $facture->client;

        return [
            'iban' => $iban,
            'creancier' => [
                'nom' => $societe->nom,
                'rue' => $clinique->rue,
                'npa' => $clinique->npa,
                'localite' => $clinique->ville,
                'pays' => 'CH',
            ],
            'debiteur' => $client === null || blank($client->nom) ? null : [
                'nom' => trim(($client->prenom ?? '').' '.$client->nom),
                'rue' => $client->rue,
                'npa' => $client->npa,
                'localite' => $client->localite,
                'pays' => 'CH',
            ],
            'montant' => $reste,
            'devise' => 'CHF',
            'type_reference' => $estQr ? 'QRR' : 'SCOR',
            // Le numéro de la facture, retrouvé tel quel dans le relevé de la banque
            'reference' => $estQr ? self::referenceQr($facture->organisation_id.str_pad((string) $facture->id, 12, '0', STR_PAD_LEFT)) : self::referenceCreancier((string) $facture->numero),
            'message' => 'Facture '.$facture->numero,
        ];
    }
}
