<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ServiceClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\ModifierServicesCliniqueRequest;
use App\Http\Resources\V1\CliniqueResource;
use App\Models\Clinique;
use App\Models\CliniqueEspece;
use App\Models\CliniqueService;
use App\Models\Sejour;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Services d'une clinique de l'organisation (écran Organisation, domaine
 * `organisation`) : ce qu'elle propose ouvre les modules de l'espace
 * vétérinaire et paraît dans l'annuaire. Résolution explicite depuis
 * l'organisation du jeton (clinique sœur comprise), comme les horaires.
 * Couper l'hospitalisation est refusé tant qu'un animal y séjourne, et
 * réduire les boxes sous un box occupé aussi. Seul ce qui est envoyé
 * change (04.11.2026) ; le nombre de boxes reste quand l'hospitalisation
 * se coupe.
 */
class OrganisationServicesCliniqueController extends Controller
{
    public function __invoke(ModifierServicesCliniqueRequest $request, int $clinique, ContexteClinique $contexte): CliniqueResource
    {
        // L'appartenance à l'organisation est vérifiée par la requête, avant toute validation (404)
        $id = $contexte->clinique()->societe->organisation->cliniques()->findOrFail($clinique)->id;

        // Tout se décide sur la fiche relue et verrouillée (troisième passe, 04.11.2026) : deux
        // gestes simultanés sur la même clinique attendent l'un l'autre, et les gardes (animal au
        // box, acompte) voient l'état à jour. Le verrou ne se rejoue pas sous SQLite.
        $fiche = DB::transaction(function () use ($request, $id): Clinique {
            $fiche = Clinique::withoutGlobalScopes()->with('services')->lockForUpdate()->findOrFail($id);

            // Seul ce qui est envoyé change ; un service seul s'allume ou s'éteint sans toucher aux autres
            $codes = $request->has('services')
                ? $request->codes()
                : $fiche->services->map(fn ($ligne): string => $ligne->service->value)->all();
            if ($request->has('service')) {
                $codes = array_values(array_diff($codes, [$request->validated('service')]));
                if ($request->boolean('service_enabled')) {
                    $codes[] = $request->validated('service');
                }
            }
            $changeServices = $request->has('services') || $request->has('service');
            $hospitalisation = in_array(ServiceClinique::Hospitalisation->value, $codes, true);
            // Le nombre de boxes reste quand l'hospitalisation se coupe ; il ne s'écrit que s'il est envoyé
            $boxes = $request->filled('nombre_boxes') ? (int) $request->validated('nombre_boxes') : (int) $fiche->nombre_boxes;

            $occupes = Sejour::withoutGlobalScope('clinique')->where('clinique_id', $fiche->id)->enCours();
            if ($changeServices && ! $hospitalisation && $occupes->exists()) {
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

            // L'acompte est encaissé sur le compte de paiement de la société : il doit être actif,
            // et la clinique doit encaisser avec Veterinary (pas avec son propre terminal)
            if ($request->validated('acompte_centimes') && ! $fiche->encaisseAvecVeterinary()) {
                throw ValidationException::withMessages([
                    'acompte_centimes' => $fiche->paiements_integres
                        ? 'Activez d’abord les paiements de '.($fiche->societe->nom ?? 'la société').' (Réglages, Paiements) : l’acompte y est encaissé.'
                        : 'L’acompte en ligne demande les paiements intégrés (Réglages, Paiements).',
                ]);
            }

            $fiche->update([
                ...($request->filled('nombre_boxes') ? ['nombre_boxes' => $boxes] : []),
                ...collect(['affiche_veterinaires', 'rdv_sans_compte', 'rdv_a_valider', 'urgences_a_valider'])
                    ->filter(fn (string $champ): bool => $request->has($champ))
                    ->mapWithKeys(fn (string $champ): array => [$champ => $request->boolean($champ)])
                    ->all(),
                ...($request->has('acompte_centimes') ? ['acompte_centimes' => $request->validated('acompte_centimes')] : []),
            ]);

            if ($request->has('services')) {
                $fiche->services()->whereNotIn('service', $codes)->delete();
                foreach ($request->validated('services') as $ligne) {
                    $this->allumer($fiche, $ligne['service'], $ligne['modalite'] ?? null);
                }
            }
            if ($request->has('service')) {
                if ($request->boolean('service_enabled')) {
                    $this->allumer($fiche, $request->validated('service'), $request->validated('modalite'));
                } else {
                    $fiche->services()->where('service', $request->validated('service'))->delete();
                }
            }

            $especes = $request->has('especes') ? $request->validated('especes') : null;
            if ($especes !== null) {
                $fiche->especes()->whereNotIn('espece', $especes)->delete();
            }
            if ($request->has('espece') && ! $request->boolean('espece_enabled')) {
                $fiche->especes()->where('espece', $request->validated('espece'))->delete();
            }
            $ajouts = [...($especes ?? []), ...($request->has('espece') && $request->boolean('espece_enabled') ? [$request->validated('espece')] : [])];
            // insertOrIgnore : deux ajouts simultanés du même animal ne butent pas sur l'index unique
            CliniqueEspece::query()->insertOrIgnore(array_map(fn (string $espece): array => ['clinique_id' => $fiche->id, 'espece' => $espece, 'created_at' => now(), 'updated_at' => now()], $ajouts));

            return $fiche;
        });

        return new CliniqueResource($fiche->fresh(['services', 'especes']));
    }

    /**
     * Un service allumé, sa modalité posée pour les urgences. insertOrIgnore :
     * deux allumages simultanés ne butent pas sur l'index unique (pas d'erreur 500).
     */
    private function allumer(Clinique $fiche, string $service, ?string $modalite): void
    {
        $modalite = $service === ServiceClinique::Urgences->value ? $modalite : null;
        CliniqueService::query()->insertOrIgnore(['clinique_id' => $fiche->id, 'service' => $service, 'modalite' => $modalite, 'created_at' => now(), 'updated_at' => now()]);
        $fiche->services()->where('service', $service)->update(['modalite' => $modalite]);
    }
}
