<?php

namespace Tests\Feature\Patients;

use App\Models\Clinique;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Une commande en lecture seule donne les chiffres de la fusion (étape 0,
 * 02.11.2026) : animaux réclamés, suivis par plusieurs cliniques, puces en
 * double.
 */
class PetMergeFiguresTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_commande_en_lecture_seule_donne_les_chiffres_de_la_fusion(): void
    {
        $a = Clinique::factory()->create()->societe->organisation_id;
        $b = Clinique::factory()->create()->societe->organisation_id;
        $lea = User::factory()->create();
        // Félix : chez A (réclamé par Léa), chez B, et dans l'espace de Léa (espacé autrement)
        Patient::factory()->create(['organisation_id' => $a, 'puce' => '756 098 100 234 567', 'proprietaire_id' => $lea->id]);
        Patient::factory()->create(['organisation_id' => $b, 'puce' => '756 098 100 234 567']);
        Patient::factory()->create(['organisation_id' => null, 'client_id' => null, 'puce' => '756098100234567', 'proprietaire_id' => $lea->id]);
        // Rex : seul chez A
        Patient::factory()->create(['organisation_id' => $a, 'puce' => '756 098 100 999 999']);
        $avant = DB::table('patients')->orderBy('id')->get()->toJson();

        $this->artisan('pets:merge-figures')
            ->expectsTable(['Chiffre', 'Nombre'], [
                ['Animaux en tout', 4],
                ['Animaux de clinique réclamés par un compte', 1],
                ['Animaux créés par leur propriétaire (sans clinique)', 1],
                ['Puces suivies par plusieurs organisations', 1],
                ['Puces sur plusieurs dossiers', 1],
                ['Dossiers en trop (doublons de puce)', 2],
                ['Animaux réclamés dont la puce est sur un autre dossier (à fusionner)', 2],
            ])
            ->expectsOutput('Lecture seule : rien n’a été modifié.')
            ->assertExitCode(0);

        $this->assertSame($avant, DB::table('patients')->orderBy('id')->get()->toJson());
    }
}
