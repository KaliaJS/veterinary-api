<?php

namespace Tests\Feature;

use App\Enums\DrapeauRendezVous;
use App\Enums\EtatDent;
use App\Enums\RoleLignePlanning;
use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReleveDentaire;
use App\Models\RendezVous;
use App\Models\RendezVousAudit;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataModelFoundationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_hierarchie_multi_clinique_se_construit(): void
    {
        $clinique = Clinique::factory()->create();
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);

        $this->assertNotNull($clinique->societe->organisation);
        $this->assertTrue($clinique->horaireOuverture->dimanche_ferme);
        $this->assertContains($clinique->id, $clinique->societe->organisation->cliniques->pluck('id'));
    }

    public function test_une_ligne_de_planning_porte_un_praticien_ou_une_ressource(): void
    {
        $praticien = LignePlanning::factory()->create();
        $bloc = LignePlanning::factory()->bloc()->create();

        $this->assertNotNull($praticien->employe);
        $this->assertNull($praticien->salle);
        $this->assertSame(RoleLignePlanning::Consultation, $praticien->role);
        $this->assertSame($praticien->clinique_id, $praticien->employe->clinique_id);

        $this->assertNull($bloc->employe);
        $this->assertNotNull($bloc->salle);
        $this->assertSame($bloc->clinique_id, $bloc->salle->clinique_id);
    }

    public function test_deux_rendez_vous_peuvent_se_chevaucher_sur_la_meme_ligne(): void
    {
        $premier = RendezVous::factory()->create([
            'starts_at' => today()->setTime(10, 0),
            'ends_at' => today()->setTime(10, 30),
        ]);

        $second = RendezVous::factory()->create([
            'clinique_id' => $premier->clinique_id,
            'ligne_planning_id' => $premier->ligne_planning_id,
            'starts_at' => today()->setTime(10, 0),
            'ends_at' => today()->setTime(10, 30),
        ]);

        $this->assertSame(2, $premier->lignePlanning->rendezVous()->count());
        $this->assertNotSame($premier->id, $second->id);
    }

    public function test_un_rendez_vous_annule_conserve_son_audit_par_lot(): void
    {
        $rendezVous = RendezVous::factory()->create(['flags' => [DrapeauRendezVous::AJeun]]);
        $batch = (string) Str::ulid();

        RendezVousAudit::factory()->create([
            'rendez_vous_id' => $rendezVous->id,
            'batch_id' => $batch,
            'champ' => 'status',
            'ancienne_valeur' => StatutRendezVous::Prevu->value,
            'nouvelle_valeur' => StatutRendezVous::Annule->value,
        ]);
        RendezVousAudit::factory()->create([
            'rendez_vous_id' => $rendezVous->id,
            'batch_id' => $batch,
            'champ' => 'motifAnnulation',
            'ancienne_valeur' => null,
            'nouvelle_valeur' => 'Absence praticien — congés',
        ]);

        $rendezVous->update(['status' => StatutRendezVous::Annule]);
        $rendezVous->refresh();

        $this->assertSame(StatutRendezVous::Annule, $rendezVous->status);
        $this->assertTrue($rendezVous->flags->first() === DrapeauRendezVous::AJeun);
        $this->assertSame(2, $rendezVous->audits()->where('batch_id', $batch)->count());
        $this->assertSame(
            'Absence praticien — congés',
            $rendezVous->audits()->where('champ', 'motifAnnulation')->first()->nouvelle_valeur,
        );
    }

    public function test_la_fiche_patient_porte_son_dossier(): void
    {
        $patient = Patient::factory()->create(['dette' => 120]);
        Pesee::factory()->count(3)->create(['patient_id' => $patient->id]);
        Vaccination::factory()->create(['patient_id' => $patient->id]);
        ReleveDentaire::factory()->create(['patient_id' => $patient->id, 'dent' => 104, 'etat' => EtatDent::Fracture]);

        $this->assertSame($patient->organisation_id, $patient->client->organisation_id);
        $this->assertCount(3, $patient->pesees);
        $this->assertTrue($patient->vaccinations->first()->echeance_le->isAfter($patient->vaccinations->first()->injectee_le));
        $this->assertSame(EtatDent::Fracture, $patient->relevesDentaires->first()->etat);
        $this->assertTrue($patient->aSoldeDu());
    }

    public function test_le_compteur_no_show_est_derive_des_rendez_vous(): void
    {
        $patient = Patient::factory()->create();
        $client = Client::find($patient->client_id);

        RendezVous::factory()->count(2)->pourPatient($patient)->create(['status' => StatutRendezVous::Noshow]);
        RendezVous::factory()->pourPatient($patient)->create(['status' => StatutRendezVous::Prevu]);

        $this->assertSame(2, $client->nombreNoShows());
    }
}
