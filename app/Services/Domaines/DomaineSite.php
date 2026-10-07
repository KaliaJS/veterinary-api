<?php

namespace App\Services\Domaines;

use App\Models\SiteWeb;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cycle de vie du domaine personnalisé d'un site : connexion (déclaré
 * chez le fournisseur, en attente du DNS), vérification (actif dès que
 * le domaine pointe vers nous et, avec Cloudflare, que le certificat est
 * émis), retrait. Un domaine actif est servi par /public/domaines.
 */
class DomaineSite
{
    public const string ATTENTE = 'attente';

    public const string ACTIF = 'actif';

    public const string ERREUR = 'erreur';

    public function __construct(private FournisseurDomaines $fournisseur) {}

    public function connecter(SiteWeb $site, string $domaine): void
    {
        if ($site->domaine === $domaine) {
            $this->verifier($site);

            return;
        }

        $this->retirer($site);

        try {
            $identifiant = $this->fournisseur->ajouter($domaine);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['domaine' => $e->getMessage()]);
        }

        $site->update([
            'domaine' => $domaine,
            'domaine_statut' => self::ATTENTE,
            'domaine_erreur' => null,
            'domaine_fournisseur_id' => $identifiant,
            'domaine_verifie_le' => null,
        ]);

        $this->verifier($site);
    }

    public function verifier(SiteWeb $site): void
    {
        if (! $site->domaine) {
            return;
        }

        $etat = $this->fournisseur->etat($site->domaine, $site->domaine_fournisseur_id);

        $site->update([
            'domaine_statut' => $etat['actif'] ? self::ACTIF : ($etat['erreur'] ? self::ERREUR : self::ATTENTE),
            'domaine_erreur' => $etat['erreur'],
            'domaine_verifie_le' => $etat['actif'] ? ($site->domaine_verifie_le ?? now()) : null,
        ]);
    }

    public function retirer(SiteWeb $site): void
    {
        if (! $site->domaine) {
            return;
        }

        $this->fournisseur->retirer($site->domaine, $site->domaine_fournisseur_id);

        $site->update([
            'domaine' => null,
            'domaine_statut' => null,
            'domaine_erreur' => null,
            'domaine_fournisseur_id' => null,
            'domaine_verifie_le' => null,
        ]);
    }
}
