<?php

namespace App\Services\Purchasing;

use App\Models\CommandeFournisseur;

/**
 * Une centrale d'achat reliée par son interface (03.11.2026). Vetpoint est la
 * première ; d'autres viendront, chacune avec ses produits : le panier
 * automatique ne connaît que cette interface, jamais une centrale nommée.
 * Les centrales se déclarent dans config/purchasing.php.
 */
interface PurchasingConnector
{
    /** Le nom montré à la clinique (« Vetpoint ») */
    public function name(): string;

    /** La clinique a relié cette centrale (sa clé, son compte) */
    public function isLinked(int $clinicId): bool;

    /**
     * Les lignes que la centrale ne peut pas livrer maintenant (indisponibles,
     * ou pas dans son catalogue), avec la raison.
     *
     * @return array<int, string> id de ligne => raison
     *
     * @throws PurchasingFailure quand la centrale ne répond pas
     */
    public function unavailable(CommandeFournisseur $order): array;

    /**
     * Envoie le panier et le marque envoyé. Sans choix (panier qui part seul) :
     * l'adresse par défaut du compte, livraison ordinaire.
     *
     * @param  array{adresse_id?: string|null, livraison?: string, date_souhaitee?: string|null}  $choice
     *
     * @throws PurchasingFailure quand il n'est pas parti, avec la raison
     */
    public function send(CommandeFournisseur $order, array $choice = []): void;

    /**
     * Les adresses de livraison du compte de la clinique chez la centrale.
     *
     * @return list<array{id: string, libelle: string, par_defaut: bool}>
     *
     * @throws PurchasingFailure quand la centrale ne répond pas
     */
    public function addresses(int $clinicId): array;

    /**
     * Un envoi parti sans réponse (peut-être arrivé chez la centrale) : ce
     * qu'il faut dire ; null si rien n'est en suspens. Un panier en suspens ne
     * se modifie, ne s'efface ni ne part autrement.
     */
    public function pendingSend(CommandeFournisseur $order): ?string;
}
