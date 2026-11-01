<?php

namespace Tests\Feature\Stupefiants;

use App\Auth\ContexteClinique;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\GestionnaireStock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le registre des stupéfiants mis aux normes (01.11.2026, demande de Jim ;
 * art. 57, 63 et 64 OCStup, directives OSAV du 6.10.2025) : une fiche par
 * substance, chaque ligne typée (réception, administration, remise,
 * perte, vol, retour, élimination, correction, inventaire, visa) avec le
 * stock après elle et les noms figés ; produits marqués avec leur
 * tableau ; bilan d'une période ; journal immuable.
 */
class RegistreStupefiantTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Produit $substance;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Anna Meyer']);

        $organisation = $this->clinique->societe->organisation_id;
        $this->substance = Produit::factory()->stupefiant()->create(['organisation_id' => $organisation, 'nom' => 'Méthadone 10 mg/ml', 'tableau_stupefiant' => 'a']);
        $client = Client::factory()->create(['organisation_id' => $organisation, 'civilite' => 'M.', 'nom' => 'Meunier']);
        $this->patient = Patient::factory()->for($client)->create(['organisation_id' => $organisation, 'nom' => 'Pilou']);
    }

    private function inscrire(array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $this->substance->id, ...$donnees], $this->enTetes($this->jeton));
    }

    private function recevoir(float $quantite = 20): void
    {
        $this->inscrire(['type' => 'reception', 'quantite' => $quantite, 'fournisseur' => 'Grossiste', 'bulletin' => 'BL-1', 'bulletin_le' => now('Europe/Zurich')->toDateString()])->assertCreated();
    }

    public function test_une_reception_porte_le_fournisseur_le_bulletin_et_sa_date(): void
    {
        $this->inscrire(['type' => 'reception', 'quantite' => 20])->assertUnprocessable()->assertJsonValidationErrors(['fournisseur' => 'Indiquez le fournisseur.']);

        $this->inscrire(['type' => 'reception', 'quantite' => 20, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-88214', 'bulletin_le' => '2026-10-14'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'reception')
            ->assertJsonPath('data.quantite', 20)
            ->assertJsonPath('data.solde_apres', 20)
            ->assertJsonPath('data.fournisseur', 'Vetpoint')
            ->assertJsonPath('data.bulletin', 'BL-88214')
            ->assertJsonPath('data.bulletin_le', '2026-10-14')
            ->assertJsonPath('data.patient_id', null)
            ->assertJsonPath('data.employe_id', $this->employe->id)
            ->assertJsonPath('data.signataire_nom', 'Dr Anna Meyer')
            ->assertJsonPath('data.clinique_id', $this->clinique->id);
    }

    public function test_une_administration_inscrit_l_animal_et_son_detenteur_et_la_quantite_sort(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'administration', 'quantite' => 0.4])->assertUnprocessable()->assertJsonValidationErrors(['patient_id' => 'Indiquez l’animal.']);

        $this->inscrire(['type' => 'administration', 'quantite' => 0.125, 'patient_id' => $this->patient->id])
            ->assertCreated()
            ->assertJsonPath('data.quantite', -0.125)
            ->assertJsonPath('data.solde_apres', 19.875)
            ->assertJsonPath('data.patient_nom', 'Pilou')
            ->assertJsonPath('data.detenteur_nom', 'M. Meunier')
            ->assertJsonPath('data.ordonnance_numero', null)
            ->assertJsonPath('data.motif', 'Administration');
    }

    /** Une substance du tableau b : sa remise se fait sur une ordonnance ordinaire de l'app (art. 50 OCStup) */
    private function tableauB(): void
    {
        $this->substance->update(['tableau_stupefiant' => 'b']);
    }

    /** Une ordonnance de l'app : signée pour cet animal, avec cette substance */
    private function ordonnance(array $attributs = [], ?Produit $produit = null): Ordonnance
    {
        app(ContexteClinique::class)->oublier();
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'employe_id' => $this->employe->id, ...$attributs]);
        OrdonnanceLigne::factory()->create(['ordonnance_id' => $ordonnance->id, 'produit_id' => ($produit ?? $this->substance)->id]);

        return $ordonnance;
    }

    /**
     * Une remise se fait sur une ordonnance choisie dans l'app, plus un numéro tapé (accord de Jim, 01.11.2026) :
     * signée pour cet animal, en cours, avec cette substance ; son numéro est figé sur la ligne
     */
    public function test_une_remise_se_fait_sur_une_ordonnance_de_l_app(): void
    {
        $this->tableauB();
        $this->recevoir();
        $bonne = $this->ordonnance(['numero' => 'RX-2026-041']);
        $autreAnimal = $this->ordonnance(['patient_id' => Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id])->id]);
        $autreProduit = $this->ordonnance([], Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]));
        $cloturee = $this->ordonnance(['statut' => 'stop']);
        $ailleurs = Ordonnance::factory()->create();
        $remise = fn (array $plus) => $this->inscrire(['type' => 'remise', 'quantite' => 2, 'patient_id' => $this->patient->id, ...$plus]);

        $remise([])->assertUnprocessable()->assertJsonValidationErrors(['ordonnance_id' => 'Choisissez l’ordonnance de la remise.']);
        // Un numéro tapé ne suffit plus
        $remise(['ordonnance_numero' => 'RX-2026-041'])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_id');
        foreach ([$autreAnimal, $autreProduit, $cloturee, $ailleurs] as $mauvaise) {
            $remise(['ordonnance_id' => $mauvaise->id])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_id');
        }
        $this->assertSame(0, MouvementStupefiant::query()->where('type', 'remise')->count());

        $remise(['ordonnance_id' => $bonne->id])->assertCreated()
            ->assertJsonPath('data.solde_apres', 18)
            ->assertJsonPath('data.ordonnance_id', $bonne->id)
            ->assertJsonPath('data.ordonnance_numero', 'RX-2026-041');
    }

    /** Les ordonnances à proposer : celles en cours de cet animal qui prescrivent cette substance */
    public function test_les_ordonnances_proposees_pour_une_remise(): void
    {
        $this->tableauB();
        $bonne = $this->ordonnance(['numero' => 'RX-2026-041']);
        $this->ordonnance([], Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]));
        $this->ordonnance(['statut' => 'stop']);

        $this->getJson('/api/v1/stupefiants/ordonnances?patient_id='.$this->patient->id.'&produit_id='.$this->substance->id, $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bonne->id)
            ->assertJsonPath('data.0.numero', 'RX-2026-041')
            ->assertJsonPath('data.0.veterinaire', 'Dr Anna Meyer');
    }

    /** Perte et vol : sans animal, avec ce qui s'est passé (demande de Jim : les vols, pour toute la Suisse) */
    public function test_une_perte_et_un_vol_s_inscrivent_sans_animal_avec_ce_qui_s_est_passe(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'vol', 'quantite' => 5])->assertUnprocessable()->assertJsonValidationErrors(['motif' => 'Décrivez ce qui s’est passé.']);

        $this->inscrire(['type' => 'perte', 'quantite' => 0.5, 'motif' => 'Flacon cassé à la préparation'])->assertCreated()
            ->assertJsonPath('data.type_libelle', 'Perte')->assertJsonPath('data.quantite', -0.5)->assertJsonPath('data.patient_id', null);
        $this->inscrire(['type' => 'vol', 'quantite' => 5, 'motif' => 'Coffre forcé, plainte déposée le 01.11.2026'])->assertCreated()
            ->assertJsonPath('data.type_libelle', 'Vol')->assertJsonPath('data.solde_apres', 14.5);
    }

    public function test_une_elimination_dit_comment_et_un_retour_dit_a_qui(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'elimination', 'quantite' => 3])->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->inscrire(['type' => 'elimination', 'quantite' => 3, 'motif' => 'Envoi recommandé n° 98.12.345678 au pharmacien cantonal'])->assertCreated()->assertJsonPath('data.quantite', -3);
        $this->inscrire(['type' => 'retour', 'quantite' => 2])->assertUnprocessable()->assertJsonValidationErrors('fournisseur');
        $this->inscrire(['type' => 'retour', 'quantite' => 2, 'fournisseur' => 'Vetpoint'])->assertCreated()->assertJsonPath('data.solde_apres', 15);
    }

    public function test_une_sortie_ne_depasse_jamais_le_stock(): void
    {
        $this->recevoir(1);
        $this->inscrire(['type' => 'perte', 'quantite' => 1.5, 'motif' => 'Flacon cassé'])->assertUnprocessable()->assertJsonValidationErrors(['quantite' => 'Le registre n’en compte que 1 : une sortie ne dépasse jamais le stock.']);
    }

    /** Une erreur se corrige par une ligne liée à la ligne fausse, sans inventer d'animal ni de réception */
    public function test_une_correction_pointe_la_ligne_corrigee_et_dit_pourquoi(): void
    {
        $this->recevoir();
        $fausse = $this->inscrire(['type' => 'administration', 'quantite' => 1.2, 'patient_id' => $this->patient->id])->assertCreated()->json('data.id');

        $this->inscrire(['type' => 'correction', 'quantite' => 1.08])->assertUnprocessable()->assertJsonValidationErrors(['corrige_id', 'motif']);
        $this->inscrire(['type' => 'correction', 'quantite' => 0, 'corrige_id' => $fausse, 'motif' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('quantite');

        $this->inscrire(['type' => 'correction', 'quantite' => 1.08, 'corrige_id' => $fausse, 'motif' => 'Saisi 1,2 ml au lieu de 0,12 ml'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'correction')
            ->assertJsonPath('data.quantite', 1.08)
            ->assertJsonPath('data.solde_apres', 19.88)
            ->assertJsonPath('data.patient_id', null)
            ->assertJsonPath('data.corrige.id', $fausse)
            ->assertJsonPath('data.corrige.type_libelle', 'Administration');

        // Une correction ne corrige ni un visa, ni une autre correction, ni une autre substance
        $correction = MouvementStupefiant::query()->latest('id')->value('id');
        $this->inscrire(['type' => 'correction', 'quantite' => 0.1, 'corrige_id' => $correction, 'motif' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('corrige_id');
    }

    /** L'inventaire : compté contre le registre ; un écart s'explique et redresse le stock (OSAV D 06) */
    public function test_l_inventaire_inscrit_l_ecart_et_l_ecart_s_explique(): void
    {
        $this->recevoir(20);
        $this->inscrire(['type' => 'inventaire', 'quantite_comptee' => 20])->assertCreated()
            ->assertJsonPath('data.quantite', 0)->assertJsonPath('data.quantite_comptee', 20)->assertJsonPath('data.motif', 'Stock compté, conforme au registre');

        $this->inscrire(['type' => 'inventaire', 'quantite_comptee' => 19.5])->assertUnprocessable()
            ->assertJsonValidationErrors(['motif' => 'Le compte diffère du registre de -0,5 : expliquez l’écart.']);
        $this->inscrire(['type' => 'inventaire', 'quantite_comptee' => 19.5, 'motif' => 'Évaporation, flacon entamé'])->assertCreated()
            ->assertJsonPath('data.quantite', -0.5)->assertJsonPath('data.solde_apres', 19.5);
    }

    public function test_le_visa_du_veterinaire_s_inscrit_sans_rien_changer(): void
    {
        $this->recevoir(20);
        // Le visa passe par son chemin, réservé au vétérinaire responsable (01.11.2026)
        $this->clinique->update(['responsable_stupefiants_id' => $this->employe->id]);
        $this->postJson('/api/v1/stupefiants/visa', ['mention' => 'Registre visé, octobre 2026'], $this->enTetes($this->jeton))->assertOk();
        $this->getJson('/api/v1/stupefiants/mouvements', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.0.type_libelle', 'Visa')->assertJsonPath('data.0.quantite', 0)->assertJsonPath('data.0.solde_apres', 20)
            ->assertJsonPath('data.0.signataire_nom', 'Dr Anna Meyer')->assertJsonPath('data.0.motif', 'Registre visé, octobre 2026');
    }

    /** Renommer quelqu'un ne change pas le registre : les noms sont figés à l'inscription */
    public function test_les_noms_sont_figes_a_l_inscription(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'administration', 'quantite' => 0.4, 'patient_id' => $this->patient->id])->assertCreated();
        $this->employe->update(['nom' => 'Dr Anna Meyer-Rochat']);
        $this->patient->update(['nom' => 'Pilou II']);

        $ligne = $this->getJson('/api/v1/stupefiants/mouvements?produit_id='.$this->substance->id, $this->enTetes($this->jeton))->assertOk()->json('data.0');
        $this->assertSame('Pilou', $ligne['patient_nom']);
        $this->assertSame('Dr Anna Meyer', $ligne['signataire_nom']);
    }

    public function test_un_produit_non_stupefiant_est_refuse(): void
    {
        $ordinaire = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $ordinaire->id, 'type' => 'reception', 'quantite' => 5, 'fournisseur' => 'x', 'bulletin' => 'BL-1', 'bulletin_le' => now('Europe/Zurich')->toDateString()], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('produit_id');
    }

    /** Marquer un produit stupéfiant, avec son tableau (jusque-là, seule la démo en avait) */
    public function test_un_produit_se_marque_stupefiant_avec_son_tableau(): void
    {
        $midazolam = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Midazolam 5 mg/ml']);

        $this->putJson("/api/v1/stupefiants/produits/{$midazolam->id}", ['est_stupefiant' => true], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['tableau' => 'Choisissez le tableau : a ou b.']);
        $this->putJson("/api/v1/stupefiants/produits/{$midazolam->id}", ['est_stupefiant' => true, 'tableau' => 'b'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.est_stupefiant', true)->assertJsonPath('data.tableau_stupefiant', 'b');
        $marque = collect($this->getJson('/api/v1/stupefiants/soldes', $this->enTetes($this->jeton))->assertOk()->json('data'))->firstWhere('nom', 'Midazolam 5 mg/ml');
        $this->assertSame('b', $marque['tableau']);

        // Sans ligne au registre, il se retire ; avec, il reste stupéfiant
        $this->putJson("/api/v1/stupefiants/produits/{$midazolam->id}", ['est_stupefiant' => false], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.tableau_stupefiant', null);
        $this->recevoir();
        $this->putJson("/api/v1/stupefiants/produits/{$this->substance->id}", ['est_stupefiant' => false], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('est_stupefiant');
    }

    /** Plus de « stock bas » dans un registre légal : le stock, le tableau et la dernière ligne */
    public function test_les_substances_disent_leur_stock_et_leur_tableau_sans_stock_bas(): void
    {
        $this->recevoir(9.5);
        $this->inscrire(['type' => 'administration', 'quantite' => 0.6, 'patient_id' => $this->patient->id])->assertCreated();

        $substance = $this->getJson('/api/v1/stupefiants/soldes', $this->enTetes($this->jeton))->assertOk()->json('data.0');
        $this->assertSame(8.9, $substance['solde']);
        $this->assertSame('a', $substance['tableau']);
        $this->assertNotNull($substance['dernier_le']);
        $this->assertArrayNotHasKey('stock_bas', $substance);
    }

    /** Le bilan d'une période (art. 57 OCStup) : stock au début, lignes avec le stock après, totaux, stock à la fin */
    public function test_le_bilan_d_une_periode_donne_stock_debut_totaux_et_stock_fin(): void
    {
        // Des lignes déjà en base : leur date de registre est le jour de l'inscription à Zurich (01.11.2026)
        $ligne = fn (array $a) => MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id, 'motif' => 'x', 'date_registre' => CarbonImmutable::parse($a['created_at'], 'UTC')->setTimezone('Europe/Zurich')->toDateString(), ...$a]));
        $ligne(['type' => 'reception', 'quantite' => 25, 'solde_apres' => 25, 'created_at' => '2025-12-20 10:00:00']);
        $ligne(['type' => 'reception', 'quantite' => 20, 'solde_apres' => 45, 'created_at' => '2026-03-01 10:00:00']);
        $ligne(['type' => 'administration', 'quantite' => -1.2, 'solde_apres' => 43.8, 'created_at' => '2026-04-01 10:00:00']);
        $ligne(['type' => 'vol', 'quantite' => -5, 'solde_apres' => 38.8, 'created_at' => '2026-05-01 10:00:00']);
        $ligne(['type' => 'correction', 'quantite' => 0.2, 'solde_apres' => 39, 'created_at' => '2026-05-02 10:00:00']);
        $ligne(['type' => 'reception', 'quantite' => 3, 'solde_apres' => 42, 'created_at' => '2027-01-02 10:00:00']);

        $fiche = $this->getJson('/api/v1/stupefiants/registre?du=2026-01-01&au=2026-12-31', $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0');
        $this->assertSame(25.0, (float) $fiche['stock_debut']);
        $this->assertSame(39.0, (float) $fiche['stock_fin']);
        $this->assertEquals(['receptions' => 20, 'utilisations' => 1.2, 'pertes' => 0, 'vols' => 5, 'retours' => 0, 'eliminations' => 0, 'corrections' => 0.2, 'ecarts_inventaire' => 0], $fiche['totaux']);
        $this->assertSame(['reception', 'administration', 'vol', 'correction'], array_column($fiche['lignes'], 'type'));
        $this->assertSame('a', $fiche['produit']['tableau']);
    }

    /** Vérification du 01.11.2026 : ce qui pouvait changer sans qu'aucun test échoue */
    public function test_une_correction_nulle_ou_d_une_autre_substance_est_refusee(): void
    {
        $this->recevoir();
        $ligne = $this->inscrire(['type' => 'administration', 'quantite' => 1, 'patient_id' => $this->patient->id])->json('data.id');
        $this->inscrire(['type' => 'correction', 'quantite' => '0.0', 'corrige_id' => $ligne, 'motif' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('quantite');

        $autre = Produit::factory()->stupefiant()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $autre->id, 'type' => 'correction', 'quantite' => 0.5, 'corrige_id' => $ligne, 'motif' => 'x'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('corrige_id');
    }

    public function test_sans_le_droit_des_stupefiants_on_ne_saisit_ni_ne_marque_rien(): void
    {
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        // Le droit d'abord : un 403, pas une erreur de saisie
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $this->substance->id], $this->enTetes($asv))->assertForbidden();
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->putJson("/api/v1/stupefiants/produits/{$produit->id}", ['est_stupefiant' => true, 'tableau' => 'a'], $this->enTetes($asv))->assertForbidden();
    }

    public function test_un_produit_tenu_dans_une_clinique_soeur_ne_se_demarque_pas(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create(['clinique_id' => $soeur->id, 'produit_id' => $this->substance->id, 'type' => 'reception', 'quantite' => 5, 'motif' => 'x', 'created_at' => now()]));
        $this->putJson("/api/v1/stupefiants/produits/{$this->substance->id}", ['est_stupefiant' => false], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('est_stupefiant');
    }

    public function test_la_date_du_bulletin_n_est_pas_dans_le_futur(): void
    {
        $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint', 'bulletin_le' => now()->addDays(3)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('bulletin_le');
    }

    /** Le bilan : stock au début avant la période, remises comptées avec les utilisations, une substance sans rien n'a pas de fiche */
    public function test_le_bilan_compte_les_remises_et_ignore_les_substances_vides(): void
    {
        $vide = Produit::factory()->stupefiant()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Fentanyl']);
        // Des lignes déjà en base : leur date de registre est le jour de l'inscription à Zurich (01.11.2026)
        $ligne = fn (array $a) => MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id, 'motif' => 'x', 'date_registre' => CarbonImmutable::parse($a['created_at'], 'UTC')->setTimezone('Europe/Zurich')->toDateString(), ...$a]));
        $ligne(['type' => 'reception', 'quantite' => 10, 'created_at' => '2026-01-01 08:00:00']);
        $ligne(['type' => 'administration', 'quantite' => -1, 'created_at' => '2026-03-01 08:00:00']);
        $ligne(['type' => 'remise', 'quantite' => -2, 'created_at' => '2026-03-02 08:00:00']);

        $fiches = collect($this->getJson('/api/v1/stupefiants/registre?du=2026-01-01&au=2026-12-31', $this->enTetes($this->jeton))->assertOk()->json('data.fiches'));
        $this->assertSame([$this->substance->id], $fiches->pluck('produit.id')->all());
        $this->assertEquals(3, $fiches[0]['totaux']['utilisations']);
        $this->assertNotContains($vide->id, $fiches->pluck('produit.id'));
        // Une ligne du premier jour, à 8 h, est dans la période : le stock au début est 0
        $this->assertEquals(0, $fiches[0]['stock_debut']);
    }

    /** Le jour d'un changement d'heure (25.10.2026, 25 heures à Zurich) : 23 h 30 est encore ce jour-là */
    public function test_le_bilan_d_un_jour_de_changement_d_heure(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'Europe/Zurich']);
        $tard = MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create([
            'clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id, 'type' => 'reception', 'quantite' => 5, 'motif' => 'x',
            'created_at' => CarbonImmutable::parse('2026-10-25 23:30', 'Europe/Zurich')->utc(), 'date_registre' => '2026-10-25',
        ]));
        $ids = collect($this->getJson('/api/v1/stupefiants/registre?du=2026-10-25&au=2026-10-25', $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0.lignes'))->pluck('id');
        $this->assertSame([$tard->id], $ids->all());
    }

    /** Le registre d'un jour suit le calendrier de la clinique : 23 h 30 à Zurich est encore ce jour-là. */
    public function test_l_export_d_un_jour_suit_le_calendrier_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'Europe/Zurich']);
        $jour = CarbonImmutable::parse('2026-07-14', 'Europe/Zurich');
        $mouvement = fn (CarbonImmutable $quand) => MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create([
            'clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id, 'type' => 'reception', 'quantite' => 5, 'motif' => 'Réception grossiste', 'created_at' => $quand->utc(), 'date_registre' => $quand->setTimezone('Europe/Zurich')->toDateString(),
        ]));
        $tard = $mouvement($jour->setTime(23, 30));
        // 00:30 le 14 à Zurich, encore le 13 en UTC : compté par « du »
        $tot = $mouvement($jour->setTime(0, 30));
        $mouvement($jour->addDay()->setTime(0, 30));
        $mouvement($jour->subDay()->setTime(23, 30));

        $ids = collect($this->getJson('/api/v1/stupefiants/registre?du=2026-07-14&au=2026-07-14', $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0.lignes'))->pluck('id');
        $this->assertEqualsCanonicalizing([$tard->id, $tot->id], $ids->all());
    }

    public function test_le_journal_est_immuable(): void
    {
        $mouvement = MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id]);

        $this->putJson("/api/v1/stupefiants/mouvements/{$mouvement->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->deleteJson("/api/v1/stupefiants/mouvements/{$mouvement->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->putJson('/api/v1/stupefiants/mouvements', [], $this->enTetes($this->jeton))->assertStatus(405);
        $this->expectException(\LogicException::class);
        $mouvement->update(['quantite' => 1]);
    }

    /** Le registre seul fait bouger le stock d'un stupéfiant (24.10.2026) : arrondi à l'unité inférieure. */
    public function test_le_registre_fait_bouger_le_stock_et_rien_d_autre(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $stock = fn (): int => (int) Stock::withoutGlobalScopes()->where('produit_id', $this->substance->id)->where('clinique_id', $this->clinique->id)->value('quantite');

        $this->recevoir(10);
        $this->assertSame(10, $stock());
        $this->inscrire(['type' => 'administration', 'patient_id' => $this->patient->id, 'quantite' => 1.2])->assertCreated();
        $this->assertSame(8, $stock());

        // Une facture, une ordonnance, un soin : rien ne sort sans le registre
        $this->assertSame(0, app(GestionnaireStock::class)->sortir($this->substance, 3, 'Ordonnance', cliniqueId: $this->clinique->id));
        $this->assertSame(8, $stock());

        // Corriger à la main : refusé, c'est le registre qui le fait
        $this->postJson("/api/v1/stocks/produits/{$this->substance->id}/ajuster", ['quantite' => -2, 'motif' => 'recep'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('quantite');
        $this->assertSame(8, $stock());
    }

    /** Une ordonnance qui a servi à une remise ne s'annule plus : le registre garde sa pièce (vérification du 01.11.2026) */
    public function test_une_ordonnance_qui_a_servi_a_une_remise_ne_s_annule_plus(): void
    {
        $this->tableauB();
        $this->recevoir();
        $ordonnance = $this->ordonnance(['numero' => 'RX-2026-777', 'created_at' => now()]);
        $this->inscrire(['type' => 'remise', 'quantite' => 2, 'patient_id' => $this->patient->id, 'ordonnance_id' => $ordonnance->id])->assertCreated();

        $this->deleteJson('/api/v1/ordonnances/'.$ordonnance->id, [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['ordonnance' => 'Cette ordonnance a servi à une remise inscrite au registre des stupéfiants : elle ne s’annule plus, clôturez-la.']);
        $this->assertNotNull($ordonnance->fresh());
        $this->assertSame($ordonnance->id, MouvementStupefiant::query()->where('type', 'remise')->sole()->ordonnance_id);
    }

    /** La liste ne montre que les ordonnances de cet animal, la plus récente d'abord, avec le vétérinaire d'une clinique sœur */
    public function test_les_ordonnances_proposees_sont_celles_de_l_animal_avec_leur_veterinaire(): void
    {
        $this->tableauB();
        $organisation = $this->clinique->societe->organisation_id;
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        app(ContexteClinique::class)->oublier();
        $veterinaireSoeur = Employe::factory()->dans($soeur)->create(['nom' => 'Dre Léa Favre']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Zurich'));
        $ancienne = $this->ordonnance(['numero' => 'RX-2026-100', 'signee_le' => '2026-10-05']);
        $recente = $this->ordonnance(['numero' => 'RX-2026-200', 'signee_le' => '2026-10-28', 'clinique_id' => $soeur->id, 'employe_id' => $veterinaireSoeur->id]);
        $this->ordonnance(['patient_id' => Patient::factory()->create(['organisation_id' => $organisation])->id]);

        $this->getJson('/api/v1/stupefiants/ordonnances?patient_id='.$this->patient->id.'&produit_id='.$this->substance->id, $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $recente->id)
            ->assertJsonPath('data.0.veterinaire', 'Dre Léa Favre')
            ->assertJsonPath('data.1.id', $ancienne->id);
    }

    /** L'ordonnance ne se donne que pour une remise */
    public function test_l_ordonnance_ne_se_donne_que_pour_une_remise(): void
    {
        $this->tableauB();
        $this->recevoir();
        $ordonnance = $this->ordonnance();
        $this->inscrire(['type' => 'administration', 'quantite' => 0.1, 'patient_id' => $this->patient->id, 'ordonnance_id' => $ordonnance->id])
            ->assertUnprocessable()->assertJsonValidationErrors('ordonnance_id');
    }

    /**
     * Tableau a : la remise se fait sur le formulaire officiel numéroté du canton (art. 50, al. 2 OCStup), pas sur une
     * ordonnance de l'app ; son numéro est inscrit (accord de Jim, 01.11.2026)
     */
    public function test_une_remise_du_tableau_a_porte_le_numero_du_formulaire_officiel(): void
    {
        $this->recevoir();
        $ordonnance = $this->ordonnance();
        $remise = fn (array $plus) => $this->inscrire(['type' => 'remise', 'quantite' => 2, 'patient_id' => $this->patient->id, ...$plus]);

        $remise([])->assertUnprocessable()->assertJsonValidationErrors(['ordonnance_officielle' => 'Indiquez le numéro du formulaire officiel d’ordonnance (tableau a).']);
        $remise(['ordonnance_id' => $ordonnance->id])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_id');
        $remise(['ordonnance_officielle' => 'RX-2026-041'])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_officielle');

        $remise(['ordonnance_officielle' => '7 012 345'])->assertCreated()
            ->assertJsonPath('data.ordonnance_numero', '7012345')
            ->assertJsonPath('data.ordonnance_id', null);
    }

    /** Une substance sans tableau : la remise attend que le tableau soit précisé, elle en dépend */
    public function test_une_remise_attend_le_tableau_de_la_substance(): void
    {
        $this->recevoir();
        $this->substance->update(['tableau_stupefiant' => null]);
        $this->inscrire(['type' => 'remise', 'quantite' => 2, 'patient_id' => $this->patient->id, 'ordonnance_officielle' => '7012345'])
            ->assertUnprocessable()->assertJsonValidationErrors(['produit_id' => 'Précisez d’abord le tableau de la substance (a ou b) : la remise en dépend.']);
    }

    /** Une ordonnance de stupéfiant est valable un mois (art. 47, al. 2 OCStup) : plus vieille, ni proposée ni acceptée */
    public function test_une_ordonnance_de_plus_d_un_mois_ne_sert_plus_a_une_remise(): void
    {
        $this->tableauB();
        $this->recevoir();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Zurich'));
        $vieille = $this->ordonnance(['signee_le' => '2026-10-14']);
        $limite = $this->ordonnance(['signee_le' => '2026-10-15']);

        $this->getJson('/api/v1/stupefiants/ordonnances?patient_id='.$this->patient->id.'&produit_id='.$this->substance->id, $this->enTetes($this->jeton))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $limite->id);
        $this->inscrire(['type' => 'remise', 'quantite' => 1, 'patient_id' => $this->patient->id, 'ordonnance_id' => $vieille->id])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_id');
        $this->inscrire(['type' => 'remise', 'quantite' => 1, 'patient_id' => $this->patient->id, 'ordonnance_id' => $limite->id])->assertCreated();
    }

    /** Le numéro officiel : des chiffres seulement ; jamais au tableau b ni pour une administration */
    public function test_le_formulaire_officiel_seulement_au_tableau_a_et_en_chiffres(): void
    {
        $this->recevoir();
        $remise = fn (array $plus) => $this->inscrire(['type' => 'remise', 'quantite' => 1, 'patient_id' => $this->patient->id, ...$plus]);
        $remise(['ordonnance_officielle' => 'AB12345'])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_officielle');
        $this->inscrire(['type' => 'administration', 'quantite' => 0.1, 'patient_id' => $this->patient->id, 'ordonnance_officielle' => '7012345'])
            ->assertUnprocessable()->assertJsonValidationErrors('ordonnance_officielle');
        $this->tableauB();
        $remise(['ordonnance_officielle' => '7012345', 'ordonnance_id' => $this->ordonnance()->id])->assertUnprocessable()->assertJsonValidationErrors('ordonnance_officielle');
    }

    /** Un tableau autre que a ou b (une valeur venue d'ailleurs) : la remise attend qu'on le précise */
    public function test_une_remise_d_un_autre_tableau_attend_qu_on_le_precise(): void
    {
        $this->recevoir();
        DB::table('produits')->where('id', $this->substance->id)->update(['tableau_stupefiant' => 'c']);
        $this->inscrire(['type' => 'remise', 'quantite' => 1, 'patient_id' => $this->patient->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['produit_id' => 'Précisez d’abord le tableau de la substance (a ou b) : la remise en dépend.']);
    }

    /** Le tableau ne change plus une fois des lignes inscrites : la base de chaque remise passée reste vraie */
    public function test_le_tableau_ne_change_plus_une_fois_des_lignes_inscrites(): void
    {
        $this->putJson('/api/v1/stupefiants/produits/'.$this->substance->id, ['est_stupefiant' => true, 'tableau' => 'b'], $this->enTetes($this->jeton))->assertOk();
        $this->recevoir();
        $this->putJson('/api/v1/stupefiants/produits/'.$this->substance->id, ['est_stupefiant' => true, 'tableau' => 'a'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['tableau' => 'Le tableau ne change plus une fois des lignes inscrites au registre.']);
        $this->assertSame('b', $this->substance->fresh()->tableau_stupefiant);
    }

    /**
     * Art. 57, al. 3 OCStup : la date du bulletin est celle de la ligne, aussi pour le bilan d'une période ; jamais
     * après aujourd'hui (heure de la clinique) ni avant le dernier visa de la substance
     */
    public function test_le_bilan_range_une_reception_a_la_date_de_son_bulletin(): void
    {
        $aujourdhui = CarbonImmutable::now('Europe/Zurich');
        $finDuMoisPasse = $aujourdhui->startOfMonth()->subDay();
        $this->inscrire(['type' => 'reception', 'quantite' => 20, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-1', 'bulletin_le' => $finDuMoisPasse->toDateString()])->assertCreated();

        $bilan = fn (CarbonImmutable $du, CarbonImmutable $au) => $this->getJson('/api/v1/stupefiants/registre?du='.$du->toDateString().'&au='.$au->toDateString(), $this->enTetes($this->jeton))->assertOk()->json('data.fiches');
        $moisPasse = $bilan($finDuMoisPasse->startOfMonth(), $finDuMoisPasse);
        $this->assertSame(20, (int) $moisPasse[0]['stock_fin']);
        $this->assertCount(1, $moisPasse[0]['lignes']);
        $ceMois = $bilan($aujourdhui->startOfMonth(), $aujourdhui);
        $this->assertSame(20, (int) $ceMois[0]['stock_debut']);
        $this->assertSame([], $ceMois[0]['lignes']);

        // À 0 h 30 à Zurich, le bulletin du jour est accepté ; le lendemain, non
        $demain = $aujourdhui->addDay();
        $this->travelTo($demain->setTime(0, 30));
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->inscrire(['type' => 'reception', 'quantite' => 1, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-2', 'bulletin_le' => $demain->toDateString()])->assertCreated();
        $this->inscrire(['type' => 'reception', 'quantite' => 1, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-3', 'bulletin_le' => $demain->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['bulletin_le' => 'La date du bulletin ne peut pas être dans le futur.']);
    }

    /** Fournisseur et bulletin : seulement pour une réception ou un retour */
    public function test_le_bulletin_ne_se_donne_qu_a_une_reception_ou_un_retour(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'administration', 'quantite' => 0.1, 'patient_id' => $this->patient->id, 'bulletin_le' => '2020-01-01', 'fournisseur' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bulletin_le', 'fournisseur']);
    }

    /** Chaque achat attesté par un bulletin de livraison (art. 62, al. 1 OCStup) : son numéro et sa date */
    public function test_une_reception_au_registre_porte_toujours_son_bulletin(): void
    {
        $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bulletin' => 'Indiquez le numéro du bulletin de livraison.', 'bulletin_le' => 'Indiquez la date du bulletin.']);
        // Une date impossible : un seul message
        $reponse = $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-1', 'bulletin_le' => '2026-13-45'])->assertUnprocessable();
        $this->assertSame(['Indiquez la date du bulletin.'], $reponse->json('errors.bulletin_le'));
        // Un retour garde son bulletin facultatif, mais le bulletin ne se donne jamais à une administration
        $this->recevoir();
        $this->inscrire(['type' => 'retour', 'quantite' => 1, 'fournisseur' => 'Vetpoint'])->assertCreated()->assertJsonPath('data.date_registre', now('Europe/Zurich')->toDateString());
        $this->inscrire(['type' => 'administration', 'quantite' => 0.1, 'patient_id' => $this->patient->id, 'bulletin' => 'BL-2'])->assertUnprocessable()->assertJsonValidationErrors('bulletin');
    }

    /** Le bilan range par date de registre et recalcule le stock après chaque ligne dans cet ordre */
    public function test_le_bilan_range_par_date_et_recalcule_le_stock(): void
    {
        $aujourdhui = CarbonImmutable::now('Europe/Zurich');
        $this->recevoir(10);
        $this->inscrire(['type' => 'administration', 'quantite' => 1, 'patient_id' => $this->patient->id])->assertCreated();
        // Saisie après l'administration, datée par son bulletin d'avant-hier
        $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-4', 'bulletin_le' => $aujourdhui->subDays(2)->toDateString()])->assertCreated();

        $lignes = $this->getJson('/api/v1/stupefiants/registre?du='.$aujourdhui->subDays(3)->toDateString().'&au='.$aujourdhui->toDateString(), $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0.lignes');
        $this->assertSame(['BL-4', 'BL-1', null], array_column($lignes, 'bulletin'));
        $this->assertEquals([5, 15, 14], array_column($lignes, 'solde_apres'));
    }

    /** Un tableau encore à préciser se précise, même avec des lignes inscrites */
    public function test_un_tableau_a_preciser_se_precise_meme_avec_des_lignes(): void
    {
        $this->recevoir();
        DB::table('produits')->where('id', $this->substance->id)->update(['tableau_stupefiant' => null]);
        $this->putJson('/api/v1/stupefiants/produits/'.$this->substance->id, ['est_stupefiant' => true, 'tableau' => 'b'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('b', $this->substance->fresh()->tableau_stupefiant);
    }

    /**
     * Un bulletin du jour du dernier inventaire, ou plus ancien, n'est jamais refusé : la ligne prend le jour de
     * l'inscription, le bulletin reste écrit avec sa date, et le stock compté ne bouge pas (vérification du 01.11.2026)
     */
    public function test_un_bulletin_plus_ancien_que_le_dernier_inventaire_date_la_ligne_du_jour(): void
    {
        $aujourdhui = CarbonImmutable::now('Europe/Zurich');
        $this->recevoir(10);
        $this->inscrire(['type' => 'inventaire', 'quantite_comptee' => 10])->assertCreated();
        $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-2', 'bulletin_le' => $aujourdhui->subDays(2)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.date_registre', $aujourdhui->toDateString())
            ->assertJsonPath('data.bulletin_le', $aujourdhui->subDays(2)->toDateString());

        $lignes = $this->getJson('/api/v1/stupefiants/registre?du='.$aujourdhui->subDays(3)->toDateString().'&au='.$aujourdhui->toDateString(), $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0.lignes');
        $this->assertSame(['reception', 'inventaire', 'reception'], array_column($lignes, 'type'));
        $this->assertEquals([10, 10, 15], array_column($lignes, 'solde_apres'));
    }

    /** Le jour du visa est clos : un bulletin de ce jour saisi le lendemain date la ligne du lendemain */
    public function test_un_bulletin_du_jour_du_visa_saisi_le_lendemain_ne_change_pas_la_periode_visee(): void
    {
        $jour = CarbonImmutable::now('Europe/Zurich');
        $this->recevoir(10);
        $this->clinique->update(['responsable_stupefiants_id' => $this->employe->id]);
        $this->postJson('/api/v1/stupefiants/visa', [], $this->enTetes($this->jeton))->assertOk();

        $this->travelTo($jour->addDay()->setTime(10, 0));
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->inscrire(['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-5', 'bulletin_le' => $jour->toDateString()])
            ->assertCreated()->assertJsonPath('data.date_registre', $jour->addDay()->toDateString());
        $fiche = $this->getJson('/api/v1/stupefiants/registre?du='.$jour->startOfYear()->toDateString().'&au='.$jour->toDateString(), $this->enTetes($this->jeton))->assertOk()->json('data.fiches.0');
        $this->assertEquals(10, $fiche['stock_fin']);
    }

    /** La date d'une ligne suit le calendrier de la clinique : à 0 h 30 à Zurich, c'est déjà ce jour-là (la veille en UTC) */
    public function test_la_date_d_une_ligne_suit_le_calendrier_de_la_clinique(): void
    {
        $this->recevoir();
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->addDay()->setTime(0, 30));
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->inscrire(['type' => 'administration', 'quantite' => 0.1, 'patient_id' => $this->patient->id])
            ->assertCreated()->assertJsonPath('data.date_registre', CarbonImmutable::now('Europe/Zurich')->toDateString());
    }

    /** Un retour garde son bulletin, mais se date du jour de l'inscription ; une élimination peut porter le bulletin d'envoi */
    public function test_un_retour_se_date_du_jour_et_une_elimination_porte_son_bulletin(): void
    {
        $aujourdhui = CarbonImmutable::now('Europe/Zurich');
        $this->recevoir();
        $this->inscrire(['type' => 'retour', 'quantite' => 1, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BR-1', 'bulletin_le' => $aujourdhui->subDays(3)->toDateString()])
            ->assertCreated()->assertJsonPath('data.date_registre', $aujourdhui->toDateString());
        $this->inscrire(['type' => 'elimination', 'quantite' => 1, 'motif' => 'Envoi recommandé au pharmacien cantonal', 'bulletin' => 'ENV-7'])
            ->assertCreated()->assertJsonPath('data.bulletin', 'ENV-7');
    }

    /** Deux arrêts : le dernier compte ; un visa à 0 h 30 à Zurich (la veille en UTC) arrête ce jour-là */
    public function test_le_dernier_arret_compte_et_se_lit_a_l_heure_de_la_clinique(): void
    {
        $jour = CarbonImmutable::now('Europe/Zurich')->subDays(3);
        // Un inventaire il y a quatre jours, un visa il y a trois jours à 0 h 30
        $this->travelTo($jour->subDay()->setTime(10, 0));
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->recevoir(10);
        $this->inscrire(['type' => 'inventaire', 'quantite_comptee' => 10])->assertCreated();
        $this->travelTo($jour->setTime(0, 30));
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->clinique->update(['responsable_stupefiants_id' => $this->employe->id]);
        $this->postJson('/api/v1/stupefiants/visa', [], $this->enTetes($this->jeton))->assertOk();

        // Le lendemain, un bulletin du jour du visa : daté du lendemain (le visa arrête ce jour, à l'heure de Zurich)
        $this->travelTo($jour->addDay()->setTime(10, 0));
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->inscrire(['type' => 'reception', 'quantite' => 1, 'fournisseur' => 'Vetpoint', 'bulletin' => 'BL-6', 'bulletin_le' => $jour->toDateString()])
            ->assertCreated()->assertJsonPath('data.date_registre', $jour->addDay()->toDateString());
    }

    /** Une date de bulletin va avec son numéro */
    public function test_une_date_de_bulletin_va_avec_son_numero(): void
    {
        $this->recevoir();
        $this->inscrire(['type' => 'retour', 'quantite' => 1, 'fournisseur' => 'Vetpoint', 'bulletin_le' => now('Europe/Zurich')->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['bulletin' => 'Indiquez le numéro du bulletin, avec sa date.']);
    }
}
