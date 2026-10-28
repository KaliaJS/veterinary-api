<?php

namespace Tests\Feature\Structure;

use App\Models\Vaccination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Test de structure des routes : chaque route authentifiée appartient à
 * un espace connu (clinique, session, propriétaire, plateforme), chaque
 * route de l'espace clinique porte `auth:sanctum`, `clinique` et
 * `organisation.ouverte`, et vérifie un droit (`can:` sur la route ou
 * autorisation visible dans son contrôleur). Les exceptions sont listées
 * ici avec leur raison : une nouvelle route sans protection fait échouer
 * le test, il faut la protéger ou l'ajouter à la liste en le justifiant.
 */
class ProtectionRoutesTest extends TestCase
{
    /** Routes publiques (sans `auth:sanctum`), par préfixe d'URI. */
    private const array PUBLIQUES = [
        'api/v1/auth/' => 'Connexion et inscription : la porte d’entrée, limitée par throttle:auth.',
        'api/v1/public/' => 'Vitrine publique (annuaire, boutique, sites, demandes sans compte), limitée en débit.',
        'api/v1/stripe/webhook' => 'Webhook Stripe, vérifié par signature.',
        'api/v1/app-store/notifications' => 'Notifications de l’App Store, vérifiées par signature.',
        'verification-cliniques/' => 'Liens signés de l’e-mail envoyé à la plateforme (middleware signed).',
        'rappels/desabonner/' => 'Lien signé « Ne plus recevoir les rappels » des e-mails aux propriétaires (middleware signed).',
        'sanctum/csrf-cookie' => 'Route de Sanctum, sans effet : l’API n’a pas de session.',
        'up' => 'Sonde de santé.',
        '/' => 'Page d’accueil du serveur.',
    ];

    /** Routes authentifiées hors de tout espace : le compte seul, avec ou sans clinique. */
    private const array SANS_ESPACE = [
        'api/v1/invitations/' => 'Seule l’adresse invitée accepte : le jeton de l’invitation borne le geste.',
        'api/v1/inscription-clinique' => 'L’organisation naît ici : aucune clinique, donc aucun contexte, encore.',
    ];

    /**
     * Routes de l'espace clinique sans droit déductible de la route ou du
     * contrôleur, et pourquoi elles n'en demandent pas.
     */
    private const array SANS_DROIT = [
        'GET api/v1/cliniques' => 'Annuaire des cliniques de l’organisation (id, nom, boxes) : tout employé, la gestion reste au domaine organisation.',
        'GET api/v1/alertes' => 'Alertes de la personne du jeton : filtrées par ses droits dans AlertesClinique.',
        'POST api/v1/alertes/lues' => 'Marque comme lues les alertes de la personne du jeton.',
        'GET api/v1/alertes/reglages' => 'Réglages d’e-mails de la personne du jeton.',
        'PUT api/v1/alertes/reglages' => 'Réglages d’e-mails de la personne du jeton.',
        'GET api/v1/vaccins' => 'Catalogue Swissmedic, données publiques de la plateforme.',
        'GET api/v1/vaccins/gtin/{gtin}' => 'Catalogue Swissmedic, données publiques de la plateforme.',
        'GET api/v1/rappels/reglages' => 'Réglages des rappels de la clinique (oui ou non, délai) : lus par toute l’équipe, modifiés avec le droit organisation.',
    ];

    /** Routes de l'espace clinique ouvertes à une organisation fermée : de quoi payer l'abonnement. */
    private const array OUVERTES_SANS_ABONNEMENT = [
        'GET api/v1/organisation',
        'GET api/v1/organisation/abonnement/apercu',
        'POST api/v1/organisation/abonnement',
        'POST api/v1/organisation/moyen-paiement/intention',
        'PUT api/v1/organisation/moyen-paiement',
        'DELETE api/v1/organisation/moyen-paiement',
        'GET api/v1/organisation/factures',
        'GET api/v1/organisation/factures/{facture}',
        'POST api/v1/organisation/factures/{facture}/regler',
        'POST api/v1/organisation/factures/{facture}/confirmer',
    ];

    /** Modèles sans scope global liés implicitement dans l'espace clinique, et pourquoi c'est sûr. */
    private const array LIAISONS_SANS_SCOPE = [
        Vaccination::class => 'Toujours sous son patient (scopé) : le contrôleur répond 404 si elle n’est pas la sienne.',
    ];

    public function test_toute_route_publique_est_prevue(): void
    {
        $imprevues = $this->routes()
            ->reject(fn (RouteLaravel $route) => $this->a($route, 'auth:sanctum'))
            ->reject(fn (RouteLaravel $route) => $this->prefixeConnu($route->uri(), self::PUBLIQUES))
            ->map(fn (RouteLaravel $route) => $this->nom($route));

        $this->assertSame([], $imprevues->values()->all(), 'Route sans auth:sanctum hors des routes publiques prévues.');

        // Les liens de vérification et de désabonnement n'ont de valeur que signés
        $this->routes()
            ->filter(fn (RouteLaravel $route) => str_starts_with($route->uri(), 'verification-cliniques/') || str_starts_with($route->uri(), 'rappels/desabonner/'))
            ->each(fn (RouteLaravel $route) => $this->assertTrue($this->a($route, 'signed'), $this->nom($route)));
    }

    public function test_toute_route_authentifiee_appartient_a_un_espace(): void
    {
        $sansEspace = $this->authentifiees()
            ->reject(fn (RouteLaravel $route) => $this->a($route, 'clinique')
                || $this->a($route, 'clinique:facultatif')
                || $this->a($route, 'proprietaire')
                || $this->a($route, 'moderateur'))
            ->reject(fn (RouteLaravel $route) => $this->prefixeConnu($route->uri(), self::SANS_ESPACE))
            ->map(fn (RouteLaravel $route) => $this->nom($route));

        $this->assertSame([], $sansEspace->values()->all(), 'Route authentifiée sans espace (clinique, session, propriétaire, plateforme).');
    }

    public function test_la_session_sans_clinique_ne_couvre_que_la_session_elle_meme(): void
    {
        $horsSession = $this->authentifiees()
            ->filter(fn (RouteLaravel $route) => $this->a($route, 'clinique:facultatif'))
            ->reject(fn (RouteLaravel $route) => str_starts_with($route->uri(), 'api/v1/auth/'))
            // Autorisation des canaux temps réel (28.10.2026) : chaque canal fait ses
            // vérifications (routes/channels.php, testées dans tests/Feature/TempsReel)
            ->reject(fn (RouteLaravel $route) => $route->uri() === 'api/v1/broadcasting/auth')
            ->map(fn (RouteLaravel $route) => $this->nom($route));

        $this->assertSame([], $horsSession->values()->all(), 'clinique:facultatif sur une route de domaine.');
    }

    public function test_chaque_route_de_l_espace_clinique_exige_jeton_clinique_et_organisation_ouverte(): void
    {
        $cliniques = $this->espaceClinique();
        $this->assertGreaterThan(200, $cliniques->count());

        foreach ($cliniques as $route) {
            $this->assertTrue($this->a($route, 'auth:sanctum'), $this->nom($route));
        }

        $sansAbonnement = $cliniques
            ->reject(fn (RouteLaravel $route) => $this->a($route, 'organisation.ouverte'))
            ->map(fn (RouteLaravel $route) => $this->nom($route))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(self::OUVERTES_SANS_ABONNEMENT, $sansAbonnement);
    }

    public function test_chaque_route_de_l_espace_clinique_verifie_un_droit(): void
    {
        $sansDroit = $this->espaceClinique()
            ->reject(fn (RouteLaravel $route) => $this->verifieUnDroit($route))
            ->map(fn (RouteLaravel $route) => $this->nom($route))
            ->values()
            ->all();

        $this->assertSame(
            [],
            array_values(array_diff($sansDroit, array_keys(self::SANS_DROIT))),
            'Route de l’espace clinique sans can: ni autorisation dans son contrôleur : la protéger, ou la justifier dans SANS_DROIT.',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::SANS_DROIT), $sansDroit)),
            'Exception devenue inutile (route protégée ou disparue) : la retirer de SANS_DROIT.',
        );
    }

    public function test_aucune_route_de_l_espace_clinique_ne_lie_un_modele_sans_scope(): void
    {
        $liaisons = [];

        foreach ($this->espaceClinique() as $route) {
            foreach ($this->methode($route)?->getParameters() ?? [] as $parametre) {
                $type = $parametre->getType();
                if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }
                $modele = $type->getName();
                if ((new $modele)->getGlobalScopes() === [] && ! array_key_exists($modele, self::LIAISONS_SANS_SCOPE)) {
                    $liaisons[] = $this->nom($route).' {'.$parametre->getName().'} '.$modele;
                }
            }
        }

        $this->assertSame([], $liaisons, 'Un modèle sans scope résolu par binding implicite serait lu dans n’importe quelle clinique.');
    }

    /** @return Collection<int, RouteLaravel> */
    private function routes()
    {
        return collect(Route::getRoutes()->getRoutes());
    }

    /** @return Collection<int, RouteLaravel> */
    private function authentifiees()
    {
        return $this->routes()->filter(fn (RouteLaravel $route) => $this->a($route, 'auth:sanctum'));
    }

    /** @return Collection<int, RouteLaravel> */
    private function espaceClinique()
    {
        return $this->routes()->filter(fn (RouteLaravel $route) => $this->a($route, 'clinique'));
    }

    /** Middleware effectif de la route (groupes compris, exclusions retirées). */
    private function a(RouteLaravel $route, string $middleware): bool
    {
        return in_array($middleware, array_diff($route->gatherMiddleware(), $route->excludedMiddleware()), true);
    }

    /** @param  array<string, string>  $prefixes */
    private function prefixeConnu(string $uri, array $prefixes): bool
    {
        foreach (array_keys($prefixes) as $prefixe) {
            $racine = rtrim($prefixe, '/');
            if ($uri === $prefixe || $uri === $racine || ($racine !== '' && str_starts_with($uri, $racine.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function verifieUnDroit(RouteLaravel $route): bool
    {
        $middlewares = array_diff($route->gatherMiddleware(), $route->excludedMiddleware());
        if (collect($middlewares)->contains(fn (string $middleware) => str_starts_with($middleware, 'can:'))) {
            return true;
        }

        $methode = $this->methode($route);
        if (! $methode) {
            return false;
        }

        if (preg_match('/->authorize\(|Gate::(authorize|allows|denies|check|any|none)\(|->can\(|->cannot\(/', $this->source($methode))) {
            return true;
        }

        // Une FormRequest dont authorize() ne se contente pas de « true »
        foreach ($methode->getParameters() as $parametre) {
            $type = $parametre->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class) && method_exists($type->getName(), 'authorize')) {
                $autorise = new ReflectionMethod($type->getName(), 'authorize');
                if (! preg_match('/return\s+true\s*;/', $this->source($autorise))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function methode(RouteLaravel $route): ?ReflectionMethod
    {
        $action = $route->getActionName();
        if ($action === 'Closure') {
            return null;
        }

        [$classe, $methode] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];

        return new ReflectionMethod($classe, $methode);
    }

    private function source(ReflectionMethod $methode): string
    {
        $lignes = file((string) $methode->getFileName());

        return implode('', array_slice($lignes, $methode->getStartLine() - 1, $methode->getEndLine() - $methode->getStartLine() + 1));
    }

    /** « GET api/v1/… » : la méthode principale et l'URI. */
    private function nom(RouteLaravel $route): string
    {
        return Str::before(implode(' ', $route->methods()), ' ').' '.$route->uri();
    }
}
