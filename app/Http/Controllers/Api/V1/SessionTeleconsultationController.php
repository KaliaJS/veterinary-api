<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tele\DemarrerSessionRequest;
use App\Http\Resources\V1\SessionTeleconsultationResource;
use App\Models\RendezVous;
use App\Models\SessionTeleconsultation;
use App\Services\ProjectionPlanningTele;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sessions de téléconsultation de la clinique du jeton. La session est
 * le dossier maître de l'appel : démarrée depuis un RDV `tele` (qui
 * passe en `cours` — projection auditée) ou en appel libre vers un
 * patient. Une seule session en cours par rendez-vous ; un appel
 * quitté peut être rappelé — nouvelle session, même RDV. Jamais de
 * suppression.
 */
class SessionTeleconsultationController extends Controller
{
    public function __construct(private ProjectionPlanningTele $projection) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SessionTeleconsultation::class);

        $sessions = SessionTeleconsultation::query()
            ->when($request->boolean('en_cours'), fn ($requete) => $requete->whereNull('terminee_le'))
            ->when($request->integer('rendez_vous_id') !== 0, fn ($requete) => $requete->where('rendez_vous_id', $request->integer('rendez_vous_id')))
            ->when($request->integer('patient_id') !== 0, fn ($requete) => $requete->where('patient_id', $request->integer('patient_id')))
            ->with($this->relations())
            ->orderByDesc('demarre_le')
            ->paginate($this->parPage($request));

        return SessionTeleconsultationResource::collection($sessions);
    }

    public function store(DemarrerSessionRequest $request): SessionTeleconsultationResource
    {
        $this->authorize('create', SessionTeleconsultation::class);

        $donnees = $request->validated();
        $rendezVous = isset($donnees['rendez_vous_id']) ? RendezVous::findOrFail($donnees['rendez_vous_id']) : null;

        if ($rendezVous !== null && SessionTeleconsultation::where('rendez_vous_id', $rendezVous->id)->whereNull('terminee_le')->exists()) {
            throw ValidationException::withMessages([
                'rendez_vous_id' => 'Un appel est déjà en cours sur ce rendez-vous.',
            ]);
        }

        $session = DB::transaction(function () use ($request, $donnees, $rendezVous): SessionTeleconsultation {
            $session = SessionTeleconsultation::create([
                'rendez_vous_id' => $rendezVous?->id,
                // Sur un RDV lié, la FK patient du RDV fait foi.
                'patient_id' => $rendezVous->patient_id ?? $donnees['patient_id'] ?? null,
                'motif' => $donnees['motif'] ?? $rendezVous->reason ?? 'Appel vidéo',
                'demarre_le' => now(),
                'demarre_par_id' => app(ContexteClinique::class)->employe()?->id,
            ]);

            $this->projection->demarrer($session, $request->user());

            return $session;
        });

        return new SessionTeleconsultationResource($session->load($this->relations()));
    }

    public function show(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->authorize('view', $session);

        return new SessionTeleconsultationResource($session->load($this->relations()));
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'patient.client',
            'demarrePar' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
        ];
    }
}
