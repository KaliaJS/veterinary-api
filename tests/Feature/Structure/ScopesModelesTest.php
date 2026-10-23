<?php

namespace Tests\Feature\Structure;

use App\Models\CliniqueEspece;
use App\Models\CliniqueService;
use App\Models\Concerns\AppartientAUneClinique;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Models\FacturationTranscription;
use App\Models\InvitationEmploye;
use App\Models\JetonApi;
use App\Models\MessageClientele;
use App\Models\OrganisationFacture;
use App\Models\SequenceNumerotation;
use App\Models\Societe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Test de structure des modèles : tout modèle dont la table porte
 * `clinique_id` ou `organisation_id` applique le scope global qui va
 * avec (AppartientAUneClinique, AppartientAUneOrganisation). Les
 * exceptions sont listées ici avec leur raison : un nouveau modèle sans
 * scope fait échouer le test, il faut lui donner le trait ou le
 * justifier dans la liste.
 */
class ScopesModelesTest extends TestCase
{
    use RefreshDatabase;

    private const array EXCEPTIONS = [
        CliniqueEspece::class => 'Réglage d’une clinique, toujours lu par la relation de sa clinique (résolue depuis le contexte ou l’organisation) ; l’annuaire public le lit hors contexte. Jamais résolu par une route.',
        CliniqueService::class => 'Réglage d’une clinique, toujours lu par la relation de sa clinique (résolue depuis le contexte ou l’organisation) ; l’annuaire public le lit hors contexte. Jamais résolu par une route.',
        FacturationTranscription::class => 'Facturation de la plateforme à l’organisation (renouvellements, planificateur), hors contexte ; jamais exposée par une route.',
        InvitationEmploye::class => 'Résolue explicitement dans l’organisation du jeton (InvitationEquipeController) ; lue sans contexte par le lien, au compte invité.',
        JetonApi::class => 'Jeton Sanctum : lu par le guard avant que le contexte existe, c’est lui qui le fonde.',
        MessageClientele::class => 'Fil clinique et propriétaire, lu depuis l’espace propriétaire par user_id (filtre explicite).',
        OrganisationFacture::class => 'Facture de la plateforme, résolue par l’organisation du jeton (facturesAbonnement) ; les webhooks la retrouvent par son paiement.',
        SequenceNumerotation::class => 'Compteur interne du Numeroteur, sous verrou, jamais exposé.',
        Societe::class => 'Structure au-dessus des scopes (organisation, sociétés, cliniques) : résolue explicitement depuis l’organisation du jeton, jamais par binding implicite.',
    ];

    public function test_tout_modele_a_clinique_ou_organisation_applique_son_scope(): void
    {
        $sansScope = [];

        foreach ($this->modelesRattaches() as $classe => [$aClinique, $aOrganisation]) {
            if (array_key_exists($classe, self::EXCEPTIONS)) {
                continue;
            }

            $traits = class_uses_recursive($classe);
            $scopes = (new $classe)->getGlobalScopes();
            $scopeClinique = in_array(AppartientAUneClinique::class, $traits, true) && array_key_exists('clinique', $scopes);
            $scopeOrganisation = in_array(AppartientAUneOrganisation::class, $traits, true) && array_key_exists('organisation', $scopes);

            if (! ($aClinique && $scopeClinique) && ! ($aOrganisation && $scopeOrganisation)) {
                $sansScope[] = $classe;
            }
        }

        $this->assertSame([], $sansScope, 'Modèle à clinique ou organisation sans scope global : lui donner le trait, ou le justifier dans EXCEPTIONS.');
    }

    public function test_chaque_exception_est_encore_un_modele_rattache_sans_scope(): void
    {
        $rattaches = $this->modelesRattaches();

        foreach (self::EXCEPTIONS as $classe => $raison) {
            $this->assertArrayHasKey($classe, $rattaches, $classe.' ne porte plus de clinique ni d’organisation : le retirer des exceptions.');
            $this->assertSame([], array_intersect([AppartientAUneClinique::class, AppartientAUneOrganisation::class], class_uses_recursive($classe)), $classe.' a maintenant son scope : le retirer des exceptions.');
            $this->assertNotSame('', trim($raison));
        }
    }

    /**
     * Modèles de app/Models dont la table porte `clinique_id` ou `organisation_id`.
     *
     * @return array<class-string<Model>, array{bool, bool}>
     */
    private function modelesRattaches(): array
    {
        $modeles = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $fichier) {
            $classe = 'App\\Models\\'.basename($fichier, '.php');
            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $colonnes = Schema::getColumnListing((new $classe)->getTable());
            $aClinique = in_array('clinique_id', $colonnes, true);
            $aOrganisation = in_array('organisation_id', $colonnes, true);

            if ($aClinique || $aOrganisation) {
                $modeles[$classe] = [$aClinique, $aOrganisation];
            }
        }

        $this->assertGreaterThan(30, count($modeles));

        return $modeles;
    }
}
