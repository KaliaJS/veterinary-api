<?php

namespace Tests\Doubles;

use App\Paiements\PasserelleIdentite;

/**
 * Double de Stripe Identity : sessions en mémoire, une par référence ;
 * le test décide de l'issue (`verifier`, `echouer`).
 */
class PasserelleIdentiteFactice implements PasserelleIdentite
{
    /** @var array<string, array{id: string, statut: string, url: ?string, erreur: ?string, prenom: ?string, nom: ?string, reference: string, metadonnees: array<string, string>}> */
    public array $sessions = [];

    /** @var list<string> */
    public array $annulees = [];

    public function creerSession(string $reference, string $urlRetour, array $metadonnees): array
    {
        foreach ($this->sessions as $session) {
            if ($session['reference'] === $reference) {
                return ['id' => $session['id'], 'url' => $session['url']];
            }
        }

        $id = 'vs_'.(count($this->sessions) + 1);
        $this->sessions[$id] = [
            'id' => $id, 'statut' => 'requires_input', 'url' => 'https://verify.stripe.com/start/'.$id, 'erreur' => null,
            'prenom' => null, 'nom' => null, 'reference' => $reference, 'metadonnees' => $metadonnees,
        ];

        return ['id' => $id, 'url' => $this->sessions[$id]['url']];
    }

    public function lireSession(string $id): array
    {
        $session = $this->sessions[$id];

        return array_intersect_key($session, array_flip(['id', 'statut', 'url', 'erreur', 'prenom', 'nom']));
    }

    public function annulerSession(string $id): void
    {
        $this->annulees[] = $id;
        $this->sessions[$id]['statut'] = 'canceled';
        $this->sessions[$id]['url'] = null;
    }

    /** La personne a montré sa pièce : Stripe lit ce nom. */
    public function verifier(string $id, string $prenom, string $nom): void
    {
        $this->sessions[$id] = [...$this->sessions[$id], 'statut' => 'verified', 'url' => null, 'prenom' => $prenom, 'nom' => $nom];
    }

    public function echouer(string $id, string $code): void
    {
        $this->sessions[$id] = [...$this->sessions[$id], 'statut' => 'requires_input', 'erreur' => $code];
    }
}
