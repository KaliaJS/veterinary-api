<?php

namespace App\Services\Domaines;

use App\Models\SiteWeb;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cycle de vie du domaine personnalisé d'un site : connexion (déclaré
 * chez le fournisseur, en attente du DNS), vérification (actif dès que
 * le domaine pointe vers nous, que l'enregistrement TXT prouve qu'il est
 * à l'organisation et, avec Cloudflare, que le certificat est émis),
 * retrait. Un domaine actif est servi par /public/domaines.
 *
 * Depuis le 23.10.2026 : un domaine actif est revérifié chaque heure et
 * se désactive après un jour sans réponse juste (DNS changé, domaine
 * vendu) ; un domaine resté en attente plus de 14 jours est retiré.
 */
class DomaineSite
{
    public const string ATTENTE = 'attente';

    public const string ACTIF = 'actif';

    public const string ERREUR = 'erreur';

    /** Nom de l'enregistrement TXT, devant le domaine */
    public const string PREFIXE_TXT = '_veterinary';

    /** Un domaine actif tient ce temps sans vérification réussie */
    public const int GRACE_HEURES = 24;

    /** Un domaine en attente est retiré après */
    public const int ATTENTE_JOURS = 14;

    public function __construct(private FournisseurDomaines $fournisseur, private ResolveurDns $dns) {}

    public function connecter(SiteWeb $site, string $domaine): void
    {
        if ($site->domaine === $domaine) {
            $this->verifier($site);

            return;
        }

        $this->retirer($site);

        try {
            $identifiant = $this->fournisseur->ajouter($domaine);
        } catch (ErreurDomaine $e) {
            throw ValidationException::withMessages(['domaine' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Fournisseur injoignable : le détail au journal, jamais à l'écran
            report($e);

            throw ValidationException::withMessages(['domaine' => 'Le service des domaines ne répond pas. Réessayez dans quelques minutes.']);
        }

        $site->update([
            'domaine' => $domaine,
            'domaine_statut' => self::ATTENTE,
            'domaine_erreur' => null,
            'domaine_fournisseur_id' => $identifiant,
            'domaine_verifie_le' => null,
            'domaine_jeton' => Str::random(32),
            'domaine_relie_le' => now(),
        ]);
        $this->oublierCacheCors();

        $this->verifier($site);
    }

    /** Les origines CORS des sites suivent les domaines reliés. */
    private function oublierCacheCors(): void
    {
        Cache::forget('sites.domaines.cors');
    }

    public function verifier(SiteWeb $site): void
    {
        if (! $site->domaine) {
            return;
        }

        $prouve = $this->prouve($site);
        $etat = $this->fournisseur->etat($site->domaine, $site->domaine_fournisseur_id);
        $erreur = $etat['erreur'] ?? ($prouve ? null : 'Ajoutez l’enregistrement TXT de vérification chez votre registraire : il prouve que le domaine est à vous.');

        if ($etat['actif'] && $prouve) {
            $site->update(['domaine_statut' => self::ACTIF, 'domaine_erreur' => null, 'domaine_verifie_le' => now()]);

            return;
        }

        // Actif, il tient un jour sans réponse juste (panne DNS passagère)
        $tient = $site->domaine_statut === self::ACTIF
            && $site->domaine_verifie_le !== null
            && $site->domaine_verifie_le->gt(now()->subHours(self::GRACE_HEURES));

        $site->update([
            'domaine_statut' => $tient ? self::ACTIF : ($etat['erreur'] ? self::ERREUR : self::ATTENTE),
            'domaine_erreur' => $erreur,
            'domaine_verifie_le' => $tient ? $site->domaine_verifie_le : null,
        ]);
        if (! $tient) {
            $this->oublierCacheCors();
        }
    }

    /** L'enregistrement TXT `_veterinary.<domaine>` porte le jeton du site. */
    private function prouve(SiteWeb $site): bool
    {
        if (config('sites.domaines.test_auto') && str_ends_with((string) $site->domaine, '.test')) {
            return true;
        }

        return $site->domaine_jeton !== null
            && in_array(self::valeurTxt($site->domaine_jeton), $this->dns->txt(self::PREFIXE_TXT.'.'.$site->domaine), true);
    }

    public static function valeurTxt(string $jeton): string
    {
        return 'veterinary-verification='.$jeton;
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
            'domaine_jeton' => null,
            'domaine_relie_le' => null,
        ]);
        $this->oublierCacheCors();
    }
}
