<?php

namespace Tests\Feature\Hospitalisation;

use App\Events\StayUpdated;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Sejour;
use App\Models\StayTreatment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La ronde garde les droits de l'écran Hospitalisation (domaine patients)
 * et l'isolement par clinique ; un geste noté sur un poste est annoncé
 * aux autres par Reverb, sans données personnelles (règle de Jim : jamais
 * de relecture régulière).
 */
class RoundAccessTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private function gestes(Sejour $sejour, int $traitement): array
    {
        $base = "/api/v1/hospitalisation/sejours/{$sejour->id}";
        $jour = aujourdhui($this->clinique)->toDateString();

        return [
            ['get', '/api/v1/hospitalisation/ronde?from='.$jour.'&to='.$jour, []],
            ['get', "{$base}/jours", []],
            ['post', "{$base}/traitements", ['label' => 'Maropitant', 'times' => [600]]],
            ['post', "{$base}/traitements/{$traitement}/arreter", []],
            ['post', "{$base}/douleurs", ['score' => 2]],
            ['post', "{$base}/observations", ['kind' => 'eats', 'value' => 'tout']],
            ['get', "{$base}/feuilles/{$jour}", []],
            ['post', "{$base}/feuilles/{$jour}/rapport", []],
            ['patch', "{$base}/feuilles/{$jour}/rapport", ['text' => 'x']],
            ['post', "{$base}/feuilles/{$jour}/rapport/signer", []],
            ['post', "{$base}/feuilles/{$jour}/rapport/envoyer", []],
        ];
    }

    public function test_chaque_geste_de_la_ronde_refuse_un_employe_sans_le_domaine_patients(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'x', 'times' => [600], 'starts_at' => now()]);

        foreach ($this->gestes($sejour, $traitement->id) as [$methode, $url, $donnees]) {
            $this->json(strtoupper($methode), $url, $donnees, $this->enTetes($jeton))->assertForbidden();
        }
    }

    public function test_le_sejour_d_une_clinique_soeur_reste_invisible(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $ici = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $ailleurs = Sejour::factory()->create(['clinique_id' => $soeur->id, 'patient_id' => $this->patient->id]);
        $traitement = StayTreatment::create(['sejour_id' => $ailleurs->id, 'label' => 'x', 'times' => [600], 'starts_at' => now()]);

        $jour = aujourdhui($this->clinique)->toDateString();
        $ronde = $this->getJson("/api/v1/hospitalisation/ronde?from={$jour}&to={$jour}", $this->enTetes($jeton))->assertOk();
        $this->assertSame([$ici->id], collect($ronde->json('data.stays'))->pluck('id')->all());

        foreach (array_slice($this->gestes($ailleurs, $traitement->id), 1) as [$methode, $url, $donnees]) {
            $this->json(strtoupper($methode), $url, $donnees, $this->enTetes($jeton))->assertNotFound();
        }
    }

    public function test_un_soin_note_sur_un_poste_est_annonce_aux_autres(): void
    {
        Event::fake([StayUpdated::class]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/douleurs", ['score' => 2], $this->enTetes($jeton))->assertCreated();
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/observations", ['kind' => 'urine', 'value' => 'oui'], $this->enTetes($jeton))->assertCreated();

        Event::assertDispatched(StayUpdated::class, function (StayUpdated $evenement) use ($sejour): bool {
            $canaux = $evenement->broadcastOn();

            return $evenement->broadcastWith() === ['id' => $sejour->id]
                && $evenement->broadcastAs() === 'sejour.updated'
                && $canaux[0] instanceof PrivateChannel
                && $canaux[0]->name === 'private-clinique.'.$this->clinique->id;
        });
        Event::assertDispatchedTimes(StayUpdated::class, 2);
    }
}
