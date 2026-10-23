<?php

namespace Tests\Feature\Diagnostic;

use App\Enums\FormuleSaas;
use App\Enums\StatutSuggestionDiagnostique;
use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\RendezVous;
use App\Models\SuggestionDiagnostique;
use App\Paiements\PasserelleAbonnement;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Les suggestions rendues se facturent sur la facture du renouvellement
 * (50 centimes pièce, sans TVA), une seule fois ; celles en échec jamais.
 * Une facture annulée les rend à facturer.
 */
class FacturationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private PasserelleAbonnementFactice $stripe;

    private Clinique $clinique;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PasserelleAbonnement::class, $this->stripe = new PasserelleAbonnementFactice);
        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill(['statut_abonnement' => null, 'periode_debut' => null, 'periode_fin' => null, 'ancre_jour' => null])->save();
        $client = $this->stripe->creerClient('Clinique', null, []);
        $this->organisation->forceFill([
            'stripe_client_id' => $client,
            'stripe_moyen_paiement_id' => $this->stripe->carteConfirmee($client),
        ])->save();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        app(AbonnementSaas::class)->souscrire($this->organisation, FormuleSaas::Cabinet);
        $this->organisation->refresh();
    }

    private function suggestion(string $le, StatutSuggestionDiagnostique $statut = StatutSuggestionDiagnostique::Prete): SuggestionDiagnostique
    {
        return SuggestionDiagnostique::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'statut' => $statut,
            'soap' => ['s' => 'x', 'o' => '', 'a' => '', 'p' => ''],
            'montant_centimes' => $statut === StatutSuggestionDiagnostique::Prete ? 50 : 0,
            'rendue_le' => CarbonImmutable::parse($le, 'Europe/Zurich')->utc(),
        ]);
    }

    public function test_les_suggestions_rendues_s_ajoutent_au_renouvellement_une_seule_fois(): void
    {
        $this->suggestion('2026-10-21 09:00');
        $this->suggestion('2026-11-19 23:30');
        $this->suggestion('2026-11-02 10:00', StatutSuggestionDiagnostique::Erreur);
        // Le jour du renouvellement : période suivante
        $this->suggestion('2026-11-20 00:30');

        $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $facture = OrganisationFacture::query()->latest('id')->firstOrFail();
        $this->assertSame(24900 + 100, $this->stripe->dernierPrelevement()['centimes']);
        $this->assertSame('Suggestions diagnostiques, du 20.10.2026 au 19.11.2026 : 2 suggestions', collect($facture->lignes)->last()['libelle']);
        $this->assertSame(2, SuggestionDiagnostique::withoutGlobalScopes()->where('organisation_facture_id', $facture->id)->count());

        $this->travelTo(CarbonImmutable::parse('2026-12-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(24900 + 50, $this->stripe->dernierPrelevement()['centimes']);
    }

    public function test_une_facture_annulee_rend_ses_suggestions_a_facturer(): void
    {
        $this->suggestion('2026-10-25 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Europe/Zurich'));

        // Passage à une formule plus chère, carte refusée : la facture est annulée
        $this->stripe->refus = 'Carte refusée.';
        try {
            app(AbonnementSaas::class)->souscrire($this->organisation->refresh(), FormuleSaas::Hopital);
            $this->fail('Le refus de la carte était attendu.');
        } catch (ValidationException) {
        }
        $this->stripe->refus = null;
        $this->assertSame(0, SuggestionDiagnostique::withoutGlobalScopes()->whereNotNull('organisation_facture_id')->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(24900 + 50, $this->stripe->dernierPrelevement()['centimes']);
    }
}
