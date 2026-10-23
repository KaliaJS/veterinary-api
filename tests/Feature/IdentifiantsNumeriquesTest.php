<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Un identifiant qui n'est pas un nombre ne trouve aucune route : 404,
 * jamais une erreur 500 dans un contrôleur qui attend un entier.
 */
class IdentifiantsNumeriquesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_identifiant_qui_n_est_pas_un_nombre_est_introuvable_cote_proprietaire(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/espace/animaux/abc', $entetes)->assertNotFound();
        $this->postJson('/api/v1/espace/rendez-vous/abc/annuler', [], $entetes)->assertNotFound();
        $this->postJson('/api/v1/espace/devis/abc/signer', [], $entetes)->assertNotFound();
    }

    public function test_un_identifiant_qui_n_est_pas_un_nombre_est_introuvable_cote_clinique(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->patchJson('/api/v1/patients/1/pesees/abc', [], $entetes)->assertNotFound();
        $this->deleteJson('/api/v1/facturation/factures/1/lignes/abc', [], $entetes)->assertNotFound();
        $this->postJson('/api/v1/hospitalisation/sejours/1/soins/abc/faire', [], $entetes)->assertNotFound();
    }
}
