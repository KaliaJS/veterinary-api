<?php

namespace Tests\Feature\Structure;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routes;
use Tests\TestCase;

/**
 * Les routes qui coûtent (Stripe, Deepgram) ou écrivent chez une clinique
 * ont leur propre limite de fréquence, au-delà de la limite générale
 * (revérification du 24.10.2026).
 */
class LimitesDeFrequenceTest extends TestCase
{
    public function test_les_routes_couteuses_ont_leur_limite(): void
    {
        $attendues = [
            'POST api/v1/rendez-vous/{rendezVous}/enregistrement' => 'throttle:transcription',
            'POST api/v1/enregistrements/{enregistrement}/parties/{numero}' => 'throttle:transcription',
            'POST api/v1/enregistrements/{enregistrement}/terminer' => 'throttle:transcription',
            'POST api/v1/enregistrements/{enregistrement}/relancer' => 'throttle:transcription',
            'DELETE api/v1/enregistrements/{enregistrement}' => 'throttle:transcription',
            'POST api/v1/facturation/factures/{facture}/encaisser' => 'throttle:stripe',
            'POST api/v1/facturation/paiements/{paiement}/annuler' => 'throttle:stripe',
            'POST api/v1/facturation/paiements/{paiement}/simuler-carte' => 'throttle:stripe',
            'POST api/v1/facturation/compte-paiement' => 'throttle:stripe',
            'PUT api/v1/facturation/compte-paiement/integration' => 'throttle:stripe',
            'POST api/v1/facturation/terminaux' => 'throttle:stripe',
            'DELETE api/v1/facturation/terminaux/{terminal}' => 'throttle:stripe',
            'POST api/v1/espace/rendez-vous/{rendezVous}/reporter' => 'throttle:espace-demandes',
            'PUT api/v1/espace/abonnement/carte' => 'throttle:espace-achats',
            'DELETE api/v1/espace/abonnement/carte' => 'throttle:espace-achats',
            // Abonnements : chaque geste appelle Stripe (24.10.2026)
            'POST api/v1/espace/abonnement/resilier' => 'throttle:espace-achats',
            'POST api/v1/espace/abonnement/reactiver' => 'throttle:espace-achats',
            'POST api/v1/espace/abonnement/factures/{facture}/regler' => 'throttle:espace-achats',
            'POST api/v1/espace/abonnement/factures/{facture}/confirmer' => 'throttle:espace-achats',
            'POST api/v1/organisation/abonnement' => 'throttle:stripe',
            'POST api/v1/organisation/moyen-paiement/intention' => 'throttle:stripe',
            'PUT api/v1/organisation/moyen-paiement' => 'throttle:stripe',
            'DELETE api/v1/organisation/moyen-paiement' => 'throttle:stripe',
            'POST api/v1/organisation/factures/{facture}/regler' => 'throttle:stripe',
            'POST api/v1/organisation/factures/{facture}/confirmer' => 'throttle:stripe',
            'POST api/v1/organisation/abonnement/resilier' => 'throttle:stripe',
            'POST api/v1/organisation/abonnement/reactiver' => 'throttle:stripe',
            'POST api/v1/organisation/packs' => 'throttle:stripe',
        ];

        $routes = collect(Routes::getRoutes()->getRoutes())->flatMap(fn (Route $route) => collect($route->methods())
            ->reject(fn (string $methode): bool => $methode === 'HEAD')
            ->mapWithKeys(fn (string $methode): array => [$methode.' '.$route->uri() => $route->gatherMiddleware()]));

        foreach ($attendues as $route => $limite) {
            $this->assertTrue($routes->has($route), "Route introuvable : {$route}");
            $this->assertContains($limite, $routes[$route], "{$route} sans {$limite}");
        }
    }
}
