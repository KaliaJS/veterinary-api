<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\AjouterPointChecklistRequest;
use App\Http\Requests\V1\Bloc\CocherPointChecklistRequest;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Intervention;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checklist de sécurité — enfants du dossier, résolus via
 * l'intervention par leur `code`. Le point `consent` ne se coche que
 * par la signature du consentement (endpoint dédié) ; seuls les points
 * ajoutés à la volée se retirent. La réponse renvoie l'intervention
 * complète : c'est l'état de la checklist entière qui intéresse l'écran.
 */
class ChecklistInterventionController extends Controller
{
    public function ajouter(AjouterPointChecklistRequest $request, Intervention $intervention): InterventionResource
    {
        $this->authorize('update', $intervention);

        $this->refuserSiFinie($intervention);

        $intervention->checklist()->create([
            'code' => 'x'.Str::lower(Str::ulid()),
            'libelle' => $request->validated('libelle'),
            'est_ajoute' => true,
        ]);

        return $this->reponse($intervention);
    }

    public function cocher(CocherPointChecklistRequest $request, Intervention $intervention, string $code): InterventionResource
    {
        $this->authorize('update', $intervention);

        $this->refuserSiFinie($intervention);

        $point = $intervention->checklist()->where('code', $code)->firstOrFail();
        $estCoche = $request->boolean('est_coche');

        if ($point->code === 'consent' && $estCoche && ! $point->est_coche) {
            throw ValidationException::withMessages([
                'est_coche' => 'Le consentement ne se coche que par sa signature.',
            ]);
        }

        $point->update(['est_coche' => $estCoche]);

        return $this->reponse($intervention);
    }

    public function retirer(Intervention $intervention, string $code): InterventionResource
    {
        $this->authorize('update', $intervention);

        $this->refuserSiFinie($intervention);

        $point = $intervention->checklist()->where('code', $code)->firstOrFail();

        if (! $point->est_ajoute) {
            throw ValidationException::withMessages([
                'code' => 'Les six points de base ne se retirent pas.',
            ]);
        }

        $point->delete();

        return $this->reponse($intervention);
    }

    private function refuserSiFinie(Intervention $intervention): void
    {
        if ($intervention->estFinie()) {
            throw ValidationException::withMessages([
                'statut' => 'La checklist d\'une intervention terminée ne bouge plus.',
            ]);
        }
    }

    private function reponse(Intervention $intervention): InterventionResource
    {
        return new InterventionResource($intervention->load([
            'patient.client',
            'chirurgien' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'checklist',
            'transitions',
        ]));
    }
}
