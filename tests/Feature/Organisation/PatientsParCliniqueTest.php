<?php

namespace Tests\Feature\Organisation;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Patients par clinique (décision de Jim, 28.10.2026, choix 2) : la carte
 * d'une clinique compte les animaux qui ont au moins un rendez-vous ou une
 * facture dans cette clinique sur les 12 derniers mois.
 */
class PatientsParCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_chaque_clinique_compte_ses_patients_des_douze_derniers_mois(): void
    {
        $clinique = Clinique::factory()->create();
        $soeur = Clinique::factory()->create(['societe_id' => $clinique->societe_id]);
        $organisation = $clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);
        $animal = fn (string $nom) => Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id, 'nom' => $nom]);
        $vu = fn (Patient $p, Clinique $c, string $quand, ?callable $etat = null) => RendezVous::factory()->pourPatient($p)
            ->when($etat, $etat)->create(['clinique_id' => $c->id, 'starts_at' => now()->modify($quand), 'ends_at' => now()->modify($quand)->addMinutes(20)]);
        $facture = fn (Patient $p, Clinique $c, string $quand) => Facture::factory()->create(['clinique_id' => $c->id, 'patient_id' => $p->id, 'emise_le' => now()->modify($quand), 'statut' => StatutFacture::Payee]);

        $felix = $animal('Félix');
        $vu($felix, $clinique, '-2 months');
        $facture($felix, $clinique, '-2 months');      // le même animal, compté une fois
        $facture($animal('Moka'), $clinique, '-3 months');
        $vu($animal('Ancien'), $clinique, '-13 months'); // hors des 12 mois
        $vu($animal('Annulé'), $clinique, '-1 month', fn ($f) => $f->annule());
        $vu($felix, $soeur, '-1 week');                 // vu aussi dans la sœur

        app(ContexteClinique::class)->oublier();
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $cartes = collect($this->getJson('/api/v1/organisation/cliniques', $this->enTetes($jeton))->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(2, $cartes[$clinique->id]['patients']);
        $this->assertSame(1, $cartes[$soeur->id]['patients']);
    }
}
