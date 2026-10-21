<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ServiceClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\ModifierServicesCliniqueRequest;
use App\Http\Resources\V1\CliniqueResource;
use App\Models\Clinique;
use App\Models\Sejour;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Services d'une clinique de l'organisation (écran Organisation, domaine
 * `organisation`) : ce qu'elle propose ouvre les modules de l'espace
 * vétérinaire et paraît dans l'annuaire. Résolution explicite depuis
 * l'organisation du jeton (clinique sœur comprise), comme les horaires.
 * Couper l'hospitalisation est refusé tant qu'un animal y séjourne, et
 * réduire les boxes sous un box occupé aussi.
 */
class OrganisationServicesCliniqueController extends Controller
{
    public function __invoke(ModifierServicesCliniqueRequest $request, int $clinique, ContexteClinique $contexte): CliniqueResource
    {
        $fiche = $contexte->clinique()->societe->organisation->cliniques()->findOrFail($clinique);

        $codes = $request->codes();
        $hospitalisation = in_array(ServiceClinique::Hospitalisation->value, $codes, true);
        $boxes = $hospitalisation ? (int) $request->validated('nombre_boxes') : 0;

        $occupes = Sejour::withoutGlobalScope('clinique')->where('clinique_id', $fiche->id)->enCours();
        if (! $hospitalisation && $occupes->exists()) {
            throw ValidationException::withMessages([
                'services' => 'Des animaux sont hospitalisés dans cette clinique : faites-les sortir avant de couper l’hospitalisation.',
            ]);
        }
        $plusHaut = (int) $occupes->max('box');
        if ($hospitalisation && $boxes < $plusHaut) {
            throw ValidationException::withMessages([
                'nombre_boxes' => "Le box {$plusHaut} est occupé : gardez au moins {$plusHaut} boxes.",
            ]);
        }

        DB::transaction(function () use ($request, $fiche, $codes, $boxes): void {
            $fiche->update([
                'nombre_boxes' => $boxes,
                'affiche_veterinaires' => $request->boolean('affiche_veterinaires'),
                'rdv_sans_compte' => $request->has('rdv_sans_compte') ? $request->boolean('rdv_sans_compte') : $fiche->rdv_sans_compte,
            ]);

            $fiche->services()->whereNotIn('service', $codes)->delete();
            foreach ($request->validated('services') as $ligne) {
                $fiche->services()->updateOrCreate(
                    ['service' => $ligne['service']],
                    ['modalite' => $ligne['service'] === ServiceClinique::Urgences->value ? $ligne['modalite'] : null],
                );
            }

            $especes = $request->validated('especes');
            $fiche->especes()->whereNotIn('espece', $especes)->delete();
            foreach ($especes as $espece) {
                $fiche->especes()->firstOrCreate(['espece' => $espece]);
            }
        });

        return new CliniqueResource($fiche->fresh(['services', 'especes']));
    }
}
