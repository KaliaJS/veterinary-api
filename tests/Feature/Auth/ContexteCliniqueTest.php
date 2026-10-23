<?php

namespace Tests\Feature\Auth;

use App\Auth\ContexteClinique;
use App\Auth\SousContexteClinique;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\JetonApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le contexte clinique (23.10.2026) : un sous-contexte rend celui d'avant,
 * et une fiche d'une autre organisation n'ouvre jamais une clinique, même
 * rattachée par erreur.
 */
class ContexteCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_sous_contexte_rend_le_contexte_d_avant(): void
    {
        [$a, $b] = [Clinique::factory()->create(), Clinique::factory()->create()];
        $contexte = app(ContexteClinique::class);
        $contexte->definir($a);

        $vue = app(SousContexteClinique::class)->pour($b, fn () => $contexte->cliniqueId());

        $this->assertSame($b->id, $vue);
        $this->assertSame($a->id, $contexte->cliniqueId());

        // Sans contexte avant, aucun après
        $contexte->oublier();
        app(SousContexteClinique::class)->pour($b, fn () => null);
        $this->assertFalse($contexte->estDefini());
    }

    public function test_une_fiche_d_une_autre_organisation_n_ouvre_pas_la_clinique(): void
    {
        $chez = Clinique::factory()->create();
        $ailleurs = Clinique::factory()->create();
        ['user' => $user, 'employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($chez);
        $this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertOk();

        // Rattachée par erreur à la clinique d'une autre organisation
        $employe->cliniques()->attach($ailleurs->id);
        $autre = $user->createToken('api');
        JetonApi::query()->whereKey($autre->accessToken->id)->update(['clinique_id' => $ailleurs->id]);
        app(ContexteClinique::class)->oublier();

        $this->getJson('/api/v1/patients', $this->enTetes($autre->plainTextToken))->assertForbidden();
        $this->assertNotSame($ailleurs->societe->organisation_id, Employe::withoutGlobalScopes()->findOrFail($employe->id)->organisation_id);
    }
}
