<?php

namespace Tests\Feature\Espace;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fiche publique d'un vétérinaire : il écrit sa présentation, ses
 * langues et son parcours ; l'annuaire la montre seulement s'il est
 * vérifié et qu'une clinique de l'annuaire le montre, avec ses postes
 * sur Veterinary (fiches employé) en plus de ce qu'il déclare.
 */
class FicheVeterinaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private function cliniqueDeLAnnuaire(): Clinique
    {
        return Clinique::factory()->create(['photo_chemin' => 'cliniques/facade.jpg', 'nom' => 'Clinique du Port', 'ville' => 'Nyon']);
    }

    public function test_le_veterinaire_ecrit_sa_fiche_publique(): void
    {
        $clinique = $this->cliniqueDeLAnnuaire();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique, ['role_libelle' => 'Vétérinaire']);

        $this->putJson('/api/v1/auth/profil-veterinaire/fiche', [
            'presentation' => '  Chats et NAC, avec douceur.  ',
            'langues' => ['fr', 'de'],
            'parcours' => [
                ['type' => 'experience', 'titre' => 'Vétérinaire assistante', 'lieu' => 'Clinique des Alpes', 'ville' => 'Sion', 'annee_debut' => 2015, 'annee_fin' => 2018],
                ['type' => 'formation', 'titre' => 'Doctorat en médecine vétérinaire', 'lieu' => 'Vetsuisse, Berne', 'annee_fin' => 2014],
            ],
        ], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.presentation', 'Chats et NAC, avec douceur.')
            ->assertJsonPath('data.langues', ['fr', 'de'])
            ->assertJsonPath('data.parcours.0.titre', 'Vétérinaire assistante')
            ->assertJsonPath('data.parcours.1.type', 'formation');

        // Toute la liste remplace la précédente ; une fin avant le début est refusée
        $this->putJson('/api/v1/auth/profil-veterinaire/fiche', ['presentation' => null, 'langues' => [], 'parcours' => [
            ['type' => 'experience', 'titre' => 'Interne', 'annee_debut' => 2019, 'annee_fin' => 2017],
        ]], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('parcours.0.annee_fin');
        $this->putJson('/api/v1/auth/profil-veterinaire/fiche', ['presentation' => null, 'langues' => ['xx'], 'parcours' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('langues.0');
        $this->putJson('/api/v1/auth/profil-veterinaire/fiche', ['presentation' => null, 'langues' => [], 'parcours' => []], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.parcours', []);
    }

    public function test_la_fiche_publique_d_un_veterinaire_verifie(): void
    {
        $clinique = $this->cliniqueDeLAnnuaire();
        $compte = User::factory()->create(['verification_veterinaire' => 'verifie', 'langues' => ['fr', 'it'], 'presentation' => 'Bonjour.']);
        $compte->specialites()->create(['specialite' => 'dermatologie']);
        $compte->parcours()->create(['type' => 'formation', 'titre' => 'Doctorat', 'lieu' => 'Vetsuisse, Zurich', 'annee_fin' => 2012, 'ordre' => 0]);
        Employe::factory()->dans($clinique)->create(['user_id' => $compte->id, 'nom' => 'Dr Paola Rossi', 'role_libelle' => 'Vétérinaire', 'entree_le' => '2020-03-01']);
        // Un ancien poste sur Veterinary, dans une autre organisation
        Employe::factory()->create([
            'user_id' => $compte->id,
            'organisation_id' => Organisation::factory()->create(['nom' => 'Vétos du Rhône'])->id,
            'role_libelle' => 'Vétérinaire',
            'entree_le' => '2016-09-01',
            'fin_contrat_le' => '2019-12-31',
            'acces_suspendu' => true,
        ]);

        $this->getJson("/api/v1/public/veterinaires/{$compte->id}")
            ->assertOk()
            ->assertJsonPath('data.nom', 'Dr Paola Rossi')
            ->assertJsonPath('data.specialites', ['dermatologie'])
            ->assertJsonPath('data.langues', ['fr', 'it'])
            ->assertJsonPath('data.exerce', [['id' => $clinique->id, 'nom' => 'Clinique du Port', 'ville' => 'Nyon', 'depuis' => 2020]])
            ->assertJsonPath('data.experiences.0.lieu', 'Clinique du Port')
            ->assertJsonPath('data.experiences.0.sur_veterinary', true)
            ->assertJsonPath('data.experiences.1.lieu', 'Vétos du Rhône')
            ->assertJsonPath('data.experiences.1.annee_fin', 2019)
            ->assertJsonPath('data.formations.0.titre', 'Doctorat');
    }

    public function test_pas_de_fiche_sans_verification_ni_clinique_qui_le_montre(): void
    {
        $clinique = $this->cliniqueDeLAnnuaire();
        $compte = User::factory()->create();
        Employe::factory()->dans($clinique)->create(['user_id' => $compte->id, 'role_libelle' => 'Vétérinaire']);

        // Pas vérifié
        $this->getJson("/api/v1/public/veterinaires/{$compte->id}")->assertNotFound();

        // Vérifié, mais la clinique masque ses vétérinaires
        $compte->forceFill(['verification_veterinaire' => 'verifie'])->save();
        $clinique->update(['affiche_veterinaires' => false]);
        $this->getJson("/api/v1/public/veterinaires/{$compte->id}")->assertNotFound();

        // Montré : la fiche existe ; un compte qui n'est pas vétérinaire n'en a jamais
        $clinique->update(['affiche_veterinaires' => true]);
        $this->getJson("/api/v1/public/veterinaires/{$compte->id}")->assertOk();
        $asv = User::factory()->create(['verification_veterinaire' => 'verifie']);
        Employe::factory()->dans($clinique)->create(['user_id' => $asv->id, 'role_libelle' => 'ASV']);
        $this->getJson("/api/v1/public/veterinaires/{$asv->id}")->assertNotFound();
    }
}
