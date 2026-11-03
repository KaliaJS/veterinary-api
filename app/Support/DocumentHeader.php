<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Clinique;

/**
 * L'en-tête d'une pièce (facture, ordonnance, devis) se fige à son
 * émission (02.11.2026, LTVA art. 26 et CO art. 958f) : le destinataire
 * (nom et adresse du client) et l'émetteur (la clinique, sa société et son
 * IDE). Changer ensuite la fiche du client ou l'adresse de la clinique ne
 * change plus une pièce émise. Seul le logo reste celui du jour : un dessin,
 * pas une mention. La section paiement de la QR-facture, elle, porte le
 * compte et l'adresse du jour du créancier : c'est là qu'on paie aujourd'hui.
 */
final class DocumentHeader
{
    /**
     * La fiche à laquelle la pièce était adressée (client_id) : on ne la
     * montre qu'à elle, jamais au propriétaire suivant de l'animal.
     *
     * @return array{client_id: int, nom: string, nom_affiche: string, rue: ?string, npa: ?string, localite: ?string}|null
     */
    public static function recipient(?Client $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'client_id' => (int) $client->id,
            'nom' => trim(($client->prenom ?? '').' '.$client->nom),
            'nom_affiche' => $client->nom_affiche,
            'rue' => $client->rue,
            'npa' => $client->npa,
            'localite' => $client->localite,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function issuer(?Clinique $clinique): ?array
    {
        if ($clinique === null) {
            return null;
        }
        $enTete = $clinique->enTeteDocument();
        unset($enTete['logo_url']);
        // Le lieu de l'ordonnance (« Lausanne, le … »)
        $enTete['lieu'] = $clinique->ville;

        return $enTete;
    }

    /**
     * L'en-tête à imprimer : celui figé à l'émission, avec le logo du jour ;
     * une pièce sans en-tête figé (brouillon) lit la clinique.
     *
     * @param  array<string, mixed>|null  $frozen
     * @return array<string, mixed>|null
     */
    public static function forPrint(?array $frozen, ?Clinique $clinique): ?array
    {
        if ($frozen === null) {
            return $clinique?->enTeteDocument();
        }

        return [...$frozen, 'logo_url' => $clinique?->enTeteDocument()['logo_url'] ?? null];
    }

    /**
     * « Rue des Pâquis 8, 1201 Genève »
     *
     * @param  array<string, mixed>|null  $recipient
     */
    public static function addressLine(?array $recipient): string
    {
        if ($recipient === null) {
            return '';
        }

        return implode(', ', array_filter([$recipient['rue'] ?? null, trim(($recipient['npa'] ?? '').' '.($recipient['localite'] ?? ''))]));
    }

    /**
     * La pièce était-elle adressée à l'une de ces fiches ? Une pièce d'avant
     * l'en-tête figé (sans client_id) suit la fiche actuelle de l'animal.
     *
     * @param  array<string, mixed>|null  $recipient
     * @param  list<int>  $clientIds
     */
    public static function isAddressedTo(?array $recipient, array $clientIds, ?int $currentClientId): bool
    {
        $clientId = $recipient['client_id'] ?? $currentClientId;

        return $clientId !== null && in_array((int) $clientId, $clientIds, true);
    }

    /**
     * La pièce a-t-elle été faite pour un propriétaire précédent ? L'animal
     * a changé de fiche depuis : le nouveau propriétaire ne la voit pas
     * (décision de Jim, 02.11.2026 : il ne voit que le médical, radios,
     * soins, vaccins ; ni ordonnance, ni devis, ni facture de l'ancien).
     *
     * Une pièce adressée à l'une des fiches de celui qui regarde, ou à une
     * fiche du même compte que la fiche actuelle, reste la sienne (la même
     * personne dont l'animal passe d'une fiche à l'autre).
     *
     * @param  array<string, mixed>|null  $recipient
     * @param  list<int>  $viewerClientIds
     */
    public static function isForFormerOwner(?array $recipient, ?int $currentClientId, array $viewerClientIds = []): bool
    {
        $clientId = $recipient['client_id'] ?? null;

        if ($clientId === null || (int) $clientId === $currentClientId || in_array((int) $clientId, $viewerClientIds, true)) {
            return false;
        }
        // Deux fiches du même compte : la même personne, pas un propriétaire précédent
        $comptes = Client::withoutGlobalScopes()->whereIn('id', [(int) $clientId, $currentClientId])->pluck('user_id', 'id');
        $compte = $comptes->get((int) $clientId);

        return $compte === null || $compte !== $comptes->get($currentClientId);
    }
}
