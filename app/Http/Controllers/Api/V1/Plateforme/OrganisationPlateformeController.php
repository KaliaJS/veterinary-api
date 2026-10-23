<?php

namespace App\Http\Controllers\Api\V1\Plateforme;

use App\Enums\VerificationOrganisation;
use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Services\Inscription\InscriptionClinique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification des cliniques inscrites (espace Plateforme) : la même
 * décision que les liens de l'e-mail de vérification, depuis une liste.
 */
class OrganisationPlateformeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('verification', VerificationOrganisation::EnAttente->value)->toString();

        $organisations = Organisation::query()
            ->where('verification', $statut)
            ->with('inscritePar')
            ->latest()
            ->get();

        return response()->json(['data' => $organisations->map(fn (Organisation $organisation): array => $this->resume($organisation))->values()]);
    }

    public function valider(Request $request, Organisation $organisation, InscriptionClinique $inscription): JsonResponse
    {
        abort_unless($organisation->verification === VerificationOrganisation::EnAttente, 422, 'Cette clinique n’est plus en attente.');
        // Jamais sa propre organisation : un autre modérateur la vérifie
        $estLaSienne = Employe::withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('user_id', $request->user()->id)->exists();
        abort_if($estLaSienne || $organisation->inscritePar?->is($request->user()), 422, 'Un autre modérateur doit vérifier votre propre clinique.');
        $inscription->valider($organisation);

        return response()->json(['data' => $this->resume($organisation->fresh('inscritePar'))]);
    }

    public function refuser(Request $request, Organisation $organisation, InscriptionClinique $inscription): JsonResponse
    {
        $donnees = $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Dites pourquoi : la clinique verra ce motif.']);
        abort_unless($organisation->verification === VerificationOrganisation::EnAttente, 422, 'Cette clinique n’est plus en attente.');
        $inscription->refuser($organisation, $donnees['note']);

        return response()->json(['data' => $this->resume($organisation->fresh('inscritePar'))]);
    }

    /** @return array<string, mixed> */
    private function resume(Organisation $organisation): array
    {
        $clinique = Clinique::withoutGlobalScopes()->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $organisation->id))->orderBy('id')->first();

        return [
            'id' => $organisation->id,
            'nom' => $organisation->nom,
            'verification' => $organisation->verification->value,
            'raison' => $organisation->verification_raison,
            'gln' => $organisation->verification_gln,
            'note' => $organisation->verification_note,
            'inscrite_le' => $organisation->created_at?->toIso8601String(),
            'veterinaire' => $organisation->inscritePar ? ['nom' => $organisation->inscritePar->name, 'email' => $organisation->inscritePar->email] : null,
            'clinique' => $clinique ? trim($clinique->nom.', '.$clinique->rue.', '.$clinique->npa.' '.$clinique->ville, ', ') : null,
        ];
    }
}
