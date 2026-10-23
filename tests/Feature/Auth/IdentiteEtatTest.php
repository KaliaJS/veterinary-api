<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\Organisation;
use App\Paiements\PasserelleIdentite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleIdentiteFactice;
use Tests\TestCase;

/**
 * État de la vérification d'identité du compte (Stripe Identity) tel que
 * le profil l'affiche : rien au départ, le badge payé, la vérification
 * portée sur la facture de la clinique inscrite, l'issue et sa raison.
 * Lecture seule, pour le compte connecté uniquement.
 */
class IdentiteEtatTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasserelleIdentite::class, new PasserelleIdentiteFactice);
    }

    public function test_un_compte_neuf_n_a_ni_verification_ni_paiement(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->getJson('/api/v1/auth/identite', $this->enTetes($jeton))
            ->assertOk()
            ->assertExactJson(['data' => [
                'statut' => null,
                'raison' => null,
                'verifiee_le' => null,
                'payee' => false,
                'sur_facture' => false,
                'avant_le' => null,
                'prix_centimes' => 200,
            ]]);
    }

    public function test_qui_a_inscrit_une_clinique_voit_la_verification_portee_sur_la_facture(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00', 'Europe/Zurich'));
        $clinique = Clinique::factory()->create();
        ['user' => $user, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        Organisation::query()->whereKey($clinique->societe->organisation_id)
            ->update(['inscrite_par' => $user->id, 'identite_avant_le' => '2026-10-30']);

        $this->getJson('/api/v1/auth/identite', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.sur_facture', true)
            ->assertJsonPath('data.avant_le', '2026-10-30')
            ->assertJsonPath('data.payee', false)
            ->assertJsonPath('data.statut', null);
    }

    public function test_une_verification_a_revoir_donne_sa_raison_et_le_badge_paye(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $user->forceFill([
            'identite_statut' => 'a_revoir',
            'identite_raison' => 'nom_different:Léa Fontaine',
            'identite_payee_le' => now(),
        ])->save();

        $this->getJson('/api/v1/auth/identite', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'a_revoir')
            ->assertJsonPath('data.raison', 'Le nom lu sur la pièce d’identité n’est pas celui que MedReg donne pour ce GLN.')
            ->assertJsonPath('data.payee', true)
            ->assertJsonPath('data.verifiee_le', null);
    }

    public function test_une_identite_verifiee_donne_sa_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $user->forceFill(['identite_statut' => 'verifiee', 'identite_verifiee_le' => now(), 'identite_payee_le' => now()])->save();

        $this->getJson('/api/v1/auth/identite', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'verifiee')
            ->assertJsonPath('data.verifiee_le', now()->toIso8601String())
            ->assertJsonPath('data.raison', null);
    }

    public function test_l_etat_de_l_identite_exige_une_session(): void
    {
        $this->getJson('/api/v1/auth/identite')->assertUnauthorized();
    }
}
