<?php

namespace Tests\Feature\Facturation;

use App\Models\Analyse;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\CommandeBoutique;
use App\Models\CommandeFournisseur;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Ordonnance;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Chaque organisation numérote ses pièces à partir de 1 : deux
 * organisations ont chacune leur FC-2026-0001 (23.10.2026 — l'unicité
 * globale du numéro faisait échouer la première facture de toute
 * organisation après la première).
 */
class NumerotationOrganisationsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_deux_organisations_emettent_chacune_leur_premiere_facture_et_leur_premier_devis(): void
    {
        $numeros = [];

        foreach ([Clinique::factory()->create(), Clinique::factory()->create()] as $clinique) {
            ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
            $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
            $patient = Patient::factory()->create(['client_id' => $client->id]);

            $facture = $this->postJson('/api/v1/facturation/factures', [
                'patient_name' => 'Rex',
                'lignes' => [['libelle' => 'Consultation', 'prix_chf' => 85, 'type' => 'acte']],
            ], $this->enTetes($jeton))->assertCreated();

            $devis = $this->postJson('/api/v1/facturation/devis', [
                'patient_id' => $patient->id,
                'lignes' => [['libelle' => 'Détartrage', 'prix_chf' => 240, 'type' => 'acte']],
            ], $this->enTetes($jeton))->assertCreated();

            $numeros[] = [$facture->json('data.numero'), $devis->json('data.numero')];
        }

        $annee = now()->year;
        $this->assertSame([["FC-{$annee}-0001", "DV-{$annee}-001"], ["FC-{$annee}-0001", "DV-{$annee}-001"]], $numeros);
        $this->assertSame(2, Facture::withoutGlobalScopes()->where('numero', "FC-{$annee}-0001")->count());
    }

    /** @return array<string, array{class-string<Model>, bool}> */
    public static function pieces(): array
    {
        // [modèle, pièce rattachée à une clinique (sinon à l'organisation)]
        return [
            'factures' => [Facture::class, true],
            'devis' => [Devis::class, false],
            'ordonnances' => [Ordonnance::class, true],
            'clôtures de caisse' => [ClotureCaisse::class, true],
            'commandes fournisseur' => [CommandeFournisseur::class, true],
            'commandes boutique' => [CommandeBoutique::class, true],
            'analyses' => [Analyse::class, true],
        ];
    }

    /** @param class-string<Model> $modele */
    #[DataProvider('pieces')]
    public function test_un_meme_numero_vit_dans_deux_organisations_mais_pas_deux_fois_dans_la_meme(string $modele, bool $parClinique): void
    {
        $a = Clinique::factory()->create();
        $b = Clinique::factory()->create();
        $chez = fn (Clinique $c): array => $parClinique
            ? ['clinique_id' => $c->id, 'numero' => 'X-0001']
            : ['organisation_id' => $c->societe->organisation_id, 'numero' => 'X-0001'];

        $modele::factory()->create($chez($a));
        $modele::factory()->create($chez($b));
        $this->assertSame(2, $modele::withoutGlobalScopes()->where('numero', 'X-0001')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $modele::factory()->create($chez($a));
    }
}
