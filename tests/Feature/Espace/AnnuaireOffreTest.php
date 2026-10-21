<?php

namespace Tests\Feature\Espace;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'annuaire public montre ce que la clinique propose : ses services,
 * les animaux soignés, et seulement ses vétérinaires vérifiés par la
 * plateforme, avec leurs spécialités. Si la clinique masque ses
 * vétérinaires, leurs spécialités restent (les icônes seules).
 */
class AnnuaireOffreTest extends TestCase
{
    use RefreshDatabase;

    public function test_seuls_les_veterinaires_verifies_et_leurs_specialites_paraissent(): void
    {
        $clinique = Clinique::factory()->sansServices()->create(['nombre_boxes' => 6]);
        $clinique->services()->create(['service' => 'hospitalisation']);
        $clinique->services()->create(['service' => 'urgences', 'modalite' => 'permanence']);
        $clinique->especes()->create(['espece' => 'CT']);
        $clinique->especes()->create(['espece' => 'CH']);

        $verifiee = User::factory()->create(['verification_veterinaire' => 'verifie']);
        $verifiee->specialites()->create(['specialite' => 'ophtalmologie']);
        Employe::factory()->dans($clinique)->create(['user_id' => $verifiee->id, 'nom' => 'Dr Léa Fontaine', 'role_libelle' => 'Vétérinaire']);

        $nonVerifie = User::factory()->create();
        $nonVerifie->specialites()->create(['specialite' => 'cardiologie']);
        Employe::factory()->dans($clinique)->create(['user_id' => $nonVerifie->id, 'role_libelle' => 'Vétérinaire']);

        $donnees = fn () => collect($this->getJson('/api/v1/public/cliniques')->assertOk()->json('data'))->firstWhere('id', $clinique->id);

        $fiche = $donnees();
        $this->assertSame([['service' => 'hospitalisation', 'modalite' => null], ['service' => 'urgences', 'modalite' => 'permanence']], $fiche['services']);
        $this->assertSame(6, $fiche['nombre_boxes']);
        $this->assertSame(['CH', 'CT'], $fiche['especes']);
        $this->assertSame(['ophtalmologie'], $fiche['specialites']);
        $this->assertSame([['nom' => 'Dr Léa Fontaine', 'specialites' => ['ophtalmologie']]], $fiche['veterinaires']);

        // Vétérinaires masqués : leurs spécialités restent, sans les noms
        $clinique->update(['affiche_veterinaires' => false]);
        $fiche = $donnees();
        $this->assertNull($fiche['veterinaires']);
        $this->assertSame(['ophtalmologie'], $fiche['specialites']);
    }
}
