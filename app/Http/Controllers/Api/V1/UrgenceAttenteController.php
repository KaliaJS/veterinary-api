<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Enums\PushType;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\FilesAttente\EnregistrerUrgenceRequest;
use App\Http\Requests\V1\FilesAttente\PlacerDepuisFileRequest;
use App\Http\Resources\V1\RendezVousResource;
use App\Http\Resources\V1\UrgenceAttenteResource;
use App\Models\Patient;
use App\Models\UrgenceAttente;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use App\Services\Notifications\Push;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * File des urgences en attente. « Placer » crée le rendez-vous (type
 * urgence, statut `salle`, arrivée à l'instant) sur le créneau donné —
 * ou le prochain libre — et retire l'entrée : le RDV créé fait foi.
 */
class UrgenceAttenteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UrgenceAttente::class);

        return UrgenceAttenteResource::collection(
            UrgenceAttente::query()
                ->orderByDesc('triage')
                ->orderBy('arrivee_le')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerUrgenceRequest $request): UrgenceAttenteResource
    {
        $this->authorize('create', UrgenceAttente::class);

        $donnees = $request->validated();

        if (($donnees['patient_id'] ?? null) !== null) {
            $donnees = [...$donnees, ...Patient::findOrFail($donnees['patient_id'])->denormalisation()];
        }

        $urgence = UrgenceAttente::create([
            ...$donnees,
            'arrivee_le' => now(),
        ]);

        // L'équipe du planning le sait sur son téléphone, sauf qui vient de l'écrire (02.11.2026)
        $niveau = [3 => 'Vital', 2 => 'Urgent', 1 => 'À voir'][(int) $urgence->triage] ?? 'Urgence';
        Push::to(
            Push::staffOf($urgence->clinique_id, DomainePermission::Planning),
            PushType::Emergency,
            'Nouvelle urgence : '.$niveau,
            trim(($urgence->patient_name ?: 'Animal').($urgence->reason ? ', '.$urgence->reason : '')),
            '/clinique/planning',
            except: $request->user()?->id,
        );

        return new UrgenceAttenteResource($urgence);
    }

    public function placer(
        PlacerDepuisFileRequest $request,
        UrgenceAttente $urgence,
        CalculateurCreneaux $calculateur,
        MutationRendezVous $mutation,
    ): RendezVousResource {
        $this->authorize('placer', $urgence);

        $donnees = $request->validated();

        if (! empty($donnees['starts_at'])) {
            // Une heure choisie : sur la ligne donnée (grille), sinon une ligne libre
            $debut = CarbonImmutable::parse($donnees['starts_at'])->utc();
            $ligneId = isset($donnees['ligne_planning_id']) ? (int) $donnees['ligne_planning_id'] : $calculateur->ligneLibreA($debut, $urgence->duree_minutes);

            if ($ligneId === null) {
                throw ValidationException::withMessages(['starts_at' => 'Cette heure n’est plus libre au planning.']);
            }
        } else {
            // Au plus tôt : aujourd'hui, sinon les jours suivants
            $creneau = $calculateur->premierCreneauLibre($urgence->duree_minutes);

            if ($creneau === null) {
                throw ValidationException::withMessages(['starts_at' => 'Aucun créneau libre dans les 30 prochains jours.']);
            }

            [$ligneId, $debut] = [$creneau['ligne_planning_id'], $creneau['debut']];
        }

        $estAujourdhui = $debut->setTimezone($calculateur->fuseau())->isSameDay($calculateur->aujourdHui());

        $attributs = $mutation->preparerCreation([
            'ligne_planning_id' => $ligneId,
            'starts_at' => $debut,
            'duree_minutes' => $urgence->duree_minutes,
            'patient_id' => $urgence->patient_id,
            'patient_name' => $urgence->patient_name,
            'species' => $urgence->species,
            'owner_name' => $urgence->owner_name,
            'type' => TypeRendezVous::Urgence,
            // Casée aujourd'hui : déjà en salle d'attente ; un autre jour : elle reviendra
            'status' => $estAujourdhui ? StatutRendezVous::Salle : StatutRendezVous::Prevu,
            'reason' => $urgence->reason,
            'arrived_at' => $estAujourdhui ? now() : null,
        ]);

        $rendezVous = DB::transaction(function () use ($mutation, $attributs, $request, $urgence) {
            $rendezVous = $mutation->creer($attributs, $request->user());
            $urgence->delete();

            return $rendezVous;
        });
        // Le propriétaire l'apprend sur son téléphone (décision de Jim, 02.11.2026)
        // un autre jour seulement : casée aujourd'hui, l'animal est déjà là
        if (! $estAujourdhui) {
            Push::appointment($rendezVous, 'confirmé', except: $request->user()?->id);
        }

        return new RendezVousResource($rendezVous);
    }

    public function destroy(UrgenceAttente $urgence): Response
    {
        $this->authorize('delete', $urgence);

        $urgence->delete();

        return response()->noContent();
    }
}
