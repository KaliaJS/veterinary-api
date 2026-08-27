<?php

namespace Database\Factories;

use App\Enums\PrioriteAnalyse;
use App\Enums\StatutAnalyse;
use App\Models\Analyse;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\PanelLabo;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analyse>
 */
class AnalyseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinique_id' => Clinique::factory(),
            'patient_id' => Patient::factory(),
            'numero' => 'A-'.now()->format('y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'statut' => StatutAnalyse::Attente,
            'priorite' => PrioriteAnalyse::Standard,
            'preleve_le' => fake()->dateTimeBetween('-2 days'),
            'prescripteur_id' => fn (array $attributes) => Employe::factory()->create(['clinique_id' => $attributes['clinique_id']])->id,
        ];
    }

    /**
     * Instantané d'un panel du référentiel de l'organisation de la
     * clinique (créé avec elle), avec ses lignes de résultats vides —
     * l'état d'une demande née par l'API.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Analyse $analyse): void {
            if ($analyse->panels()->exists()) {
                return;
            }

            $organisationId = $analyse->clinique->societe->organisation_id;

            $panel = PanelLabo::withoutGlobalScope('organisation')
                ->where('organisation_id', $organisationId)
                ->where('code', 'bioch')
                ->with('parametres')
                ->first();

            if ($panel === null) {
                return;
            }

            $analysePanel = $analyse->panels()->create([
                'panel_labo_id' => $panel->id,
                'code' => $panel->code,
                'nom' => $panel->nom,
                'tube' => $panel->tube,
            ]);

            foreach ($panel->parametres as $parametre) {
                $analyse->resultats()->create([
                    'analyse_panel_id' => $analysePanel->id,
                    'position' => $parametre->position,
                    'parametre' => $parametre->nom,
                    'unite' => $parametre->unite,
                    'borne_basse' => $parametre->borne_basse,
                    'borne_haute' => $parametre->borne_haute,
                ]);
            }
        });
    }

    public function enCours(): static
    {
        return $this->state(fn (): array => ['statut' => StatutAnalyse::Cours]);
    }

    /** Toutes les valeurs saisies dans les bornes — prête à valider. */
    public function resultatsSaisis(): static
    {
        return $this->enCours()->afterCreating(function (Analyse $analyse): void {
            foreach ($analyse->resultats as $resultat) {
                $resultat->update([
                    'valeur' => round(((float) $resultat->borne_basse + (float) $resultat->borne_haute) / 2, 2),
                    'drapeau' => null,
                ]);
            }
        });
    }
}
