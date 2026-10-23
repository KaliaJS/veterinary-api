<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerCliniqueRequest;
use App\Http\Requests\V1\Organisation\ModifierCliniqueRequest;
use App\Http\Resources\V1\CliniqueResource;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\Organisation;
use App\Models\TerminalPaiement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cliniques de l'organisation (écran Organisation). Modèle de
 * structure : résolution explicite depuis l'organisation du jeton,
 * jamais par binding implicite. La création respecte la limite de la
 * formule et pose les horaires par défaut ; la suppression est tolérée
 * pour une clinique vide seulement — une clinique avec historique se
 * désactive, elle ne s'efface pas (docs/data-model.md, décisions
 * ouvertes) — et jamais la dernière.
 */
class OrganisationCliniqueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $cliniques = $this->organisation()
            ->cliniques()
            ->with(['employes' => fn ($requete) => $requete->withoutGlobalScope('clinique')->orderBy('id'), 'employes.congesAVenir', 'employes.cliniques', 'employes.user.specialites', 'services', 'especes'])
            ->orderBy('cliniques.id')
            ->get();

        return CliniqueResource::collection($cliniques);
    }

    public function store(EnregistrerCliniqueRequest $request): CliniqueResource
    {
        $organisation = $this->organisation();
        $limite = $organisation->plan->limites()['cliniques'];

        if ($limite !== null && $organisation->cliniques()->count() >= $limite) {
            throw ValidationException::withMessages([
                'cliniques' => "Limite de {$limite} cliniques atteinte — formule supérieure requise.",
            ]);
        }

        $donnees = $request->validated();

        $clinique = DB::transaction(function () use ($donnees): Clinique {
            $clinique = Clinique::create([
                'societe_id' => $donnees['societe_id'],
                'nom' => $donnees['nom'],
                'raison_sociale' => $donnees['raison_sociale'],
                'rue' => $donnees['rue'],
                'npa' => $donnees['npa'],
                'ville' => $donnees['ville'],
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
                'tel' => $donnees['tel'] ?? null,
                'fuseau_horaire' => $donnees['fuseau_horaire'] ?? 'Europe/Zurich',
                'nombre_boxes' => $donnees['nombre_boxes'] ?? 0,
            ]);

            // Les horaires naissent avec la clinique — jamais des
            // constantes, mais des valeurs de départ modifiables. La
            // clinique visée n'est pas celle du jeton : geste de
            // structure, l'invariant d'écriture locale est contourné
            // explicitement (comme l'embauche multi-cliniques).
            HoraireOuverture::withoutEvents(fn () => HoraireOuverture::create([
                'clinique_id' => $clinique->id,
                ...HoraireOuverture::DEFAUTS,
            ]));

            return $clinique;
        });

        return new CliniqueResource($clinique->load(['employes' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'employes.congesAVenir', 'employes.cliniques', 'employes.user.specialites', 'services', 'especes']));
    }

    public function update(ModifierCliniqueRequest $request, int $clinique): CliniqueResource
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);

        // Changer de société : ses terminaux sont rattachés au compte de
        // paiement de l'ancienne ; ils se retirent d'abord (23.10.2026)
        $nouvelleSociete = $request->validated('societe_id');
        if ($nouvelleSociete !== null && (int) $nouvelleSociete !== $fiche->societe_id
            && TerminalPaiement::withoutGlobalScopes()->where('clinique_id', $fiche->id)->exists()) {
            throw ValidationException::withMessages([
                'societe_id' => 'Retirez d’abord les terminaux de la clinique : ils encaissent sur le compte de l’ancienne société.',
            ]);
        }

        $donnees = $request->validated();
        // La location Stripe des terminaux appartient au compte de l'ancienne
        // société : le premier terminal sur le nouveau compte en crée une
        if ($nouvelleSociete !== null && (int) $nouvelleSociete !== $fiche->societe_id) {
            $donnees['stripe_location_id'] = null;
        }
        $fiche->update($donnees);

        return new CliniqueResource($fiche->load(['employes' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'employes.congesAVenir', 'employes.cliniques', 'employes.user.specialites', 'services', 'especes']));
    }

    public function destroy(int $clinique): Response
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);

        if ($this->organisation()->cliniques()->count() <= 1) {
            throw ValidationException::withMessages([
                'clinique' => 'Impossible — l’organisation doit conserver au moins une clinique.',
            ]);
        }

        $porteDesDonnees = $fiche->employes()->withoutGlobalScope('clinique')->exists()
            || $fiche->salles()->withoutGlobalScope('clinique')->exists()
            || $fiche->rendezVous()->withoutGlobalScope('clinique')->exists();

        if ($porteDesDonnees) {
            throw ValidationException::withMessages([
                'clinique' => 'Cette clinique porte un historique — elle se désactive, elle ne se supprime pas.',
            ]);
        }

        DB::transaction(function () use ($fiche): void {
            $fiche->horaireOuverture()->withoutGlobalScope('clinique')->delete();
            $fiche->delete();
        });

        return response()->noContent();
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }

    private function cliniqueDeLOrganisation(int $id): Clinique
    {
        return $this->organisation()->cliniques()->findOrFail($id);
    }
}
