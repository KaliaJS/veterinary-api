<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerVaccinationRequest;
use App\Http\Resources\V1\VaccinationResource;
use App\Models\Patient;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Vaccination;
use App\Services\EtatVaccinal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Carnet de vaccination (16.10.2026) : historique complet des injections
 * (plus d écrasement : chaque injection est une ligne), état par valence
 * calculé (EtatVaccinal), saisie d une injection depuis le catalogue (ou
 * son code-barres) ou en texte libre, correction et suppression.
 */
class VaccinationController extends Controller
{
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $vaccinations = $patient->vaccinations()
            ->with(['produit.schema', 'produit.valences', 'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
            ->orderByDesc('injectee_le')->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => VaccinationResource::collection($vaccinations)->resolve($request),
            'etat' => EtatVaccinal::parValence($vaccinations),
        ]);
    }

    public function store(EnregistrerVaccinationRequest $request, Patient $patient): VaccinationResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();
        $produit = $this->produit($donnees);
        $attributs = $this->attributs($donnees, $produit);
        // Le signataire est toujours la personne connectée (jamais un collègue)
        $attributs['employe_id'] = app(ContexteClinique::class)->employe()?->id;

        $vaccination = $patient->vaccinations()->make($attributs);
        $vaccination->setRelation('produit', $produit);
        if (! array_key_exists('echeance_le', $donnees) || $donnees['echeance_le'] === null) {
            $anterieures = $patient->vaccinations()->with('produit.valences')->get();
            $vaccination->echeance_le = EtatVaccinal::echeanceProposee($patient, $vaccination, $anterieures);
        }
        $vaccination->save();

        return new VaccinationResource($vaccination->load(['produit', 'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')]));
    }

    public function update(EnregistrerVaccinationRequest $request, Patient $patient, Vaccination $vaccination): VaccinationResource
    {
        $this->authorize('update', $patient);
        abort_unless($vaccination->patient_id === $patient->id, 404);
        $this->signataireSeul($vaccination);

        $donnees = $request->validated();
        $produit = $this->produit($donnees) ?? ($donnees['produit_id'] ?? null ? null : $vaccination->produit);
        $vaccination->fill($this->attributs($donnees, $produit));
        $vaccination->save();

        return new VaccinationResource($vaccination->load(['produit', 'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')]));
    }

    public function destroy(Patient $patient, Vaccination $vaccination): JsonResponse
    {
        $this->authorize('update', $patient);
        abort_unless($vaccination->patient_id === $patient->id, 404);
        $this->signataireSeul($vaccination);
        $vaccination->delete();

        return response()->json(null, 204);
    }

    /**
     * Une injection est signée par qui l'a faite : personne d'autre ne la
     * réécrit ni ne l'efface sous son nom (24.10.2026). Une erreur d'un
     * autre se signale à lui, ou se corrige par une nouvelle injection.
     *
     * @throws AuthorizationException
     */
    private function signataireSeul(Vaccination $vaccination): void
    {
        $moi = app(ContexteClinique::class)->employe()?->id;
        if ($vaccination->employe_id !== null && $vaccination->employe_id !== $moi) {
            throw new AuthorizationException('Seule la personne qui a signé cette injection peut la corriger ou la retirer.');
        }
        // Sans signataire (injection reprise d'un ancien carnet) : l'administration
        // seule la corrige, pas n'importe quel membre de l'équipe (24.10.2026)
        if ($vaccination->employe_id === null && ! Gate::allows(DomainePermission::Organisation->value)) {
            throw new AuthorizationException('Cette injection n’a pas de signataire : seule l’administration de la clinique peut la corriger ou la retirer.');
        }
    }

    /** @param  array<string, mixed>  $donnees */
    private function produit(array $donnees): ?ProduitVeterinaire
    {
        if (! empty($donnees['produit_id'])) {
            return ProduitVeterinaire::query()->with(['valences', 'schema'])->findOrFail($donnees['produit_id']);
        }
        if (! empty($donnees['gtin'])) {
            $emballage = ProduitEmballage::query()->with(['produit.valences', 'produit.schema'])->where('gtin', $donnees['gtin'])->first();
            if ($emballage === null) {
                throw ValidationException::withMessages(['gtin' => 'Code-barres inconnu du catalogue.']);
            }

            return $emballage->produit;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function attributs(array $donnees, ?ProduitVeterinaire $produit): array
    {
        $valences = $produit?->valences->pluck('code')->all() ?: ($donnees['valences'] ?? null) ?: null;
        // Sans valence connue au catalogue, le nom court du produit prend le relais
        $nomValences = $produit?->valences->pluck('nom_court')->implode(' · ') ?: null;

        return array_filter([
            'produit_id' => $produit?->id,
            'nom' => $donnees['nom'] ?? $nomValences ?? $produit?->nom_court,
            'marque' => $donnees['marque'] ?? $produit?->nom_court,
            'valences' => $valences,
            'lot' => $donnees['lot'] ?? null,
            'gtin' => $donnees['gtin'] ?? null,
            'peremption_le' => $donnees['peremption_le'] ?? null,
            'voie' => $donnees['voie'] ?? $produit?->schema?->voie,
            'site_injection' => $donnees['site_injection'] ?? null,
            'remarque' => $donnees['remarque'] ?? null,
            'reaction' => $donnees['reaction'] ?? null,
            'injectee_le' => $donnees['injectee_le'] ?? null,
            'echeance_le' => $donnees['echeance_le'] ?? null,
        ], fn ($valeur) => $valeur !== null) + ['echeance_le' => $donnees['echeance_le'] ?? null];
    }
}
