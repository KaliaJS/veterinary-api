<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Events\SignalVisio;
use App\Events\TransfertAppelVideo;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SessionTeleconsultationResource;
use App\Models\Employe;
use App\Models\SessionTeleconsultation;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfert d'un appel vidéo (décision de Jim, 28.10.2026) : l'accueil
 * répond, puis passe l'appel à un vétérinaire. Celui qui mène l'appel
 * choisit un collègue de la clinique (droit Planning) ; le collègue est
 * prévenu sur son canal et prend l'appel. Tant qu'il ne l'a pas pris, le
 * premier reste en ligne avec le client, et peut annuler.
 */
class TransfertAppelController extends Controller
{
    public function __construct(private CalculateurPermissions $permissions) {}

    /** Les collègues de la clinique qui peuvent prendre l'appel, les vétérinaires d'abord. */
    public function collegues(SessionTeleconsultation $session): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json(['data' => $this->colleguesPossibles($session)
            ->sortBy(fn (Employe $e): string => ($e->estVeterinaire() ? '0' : '1').mb_strtolower($e->nom))
            ->values()
            ->map(fn (Employe $e): array => ['id' => $e->id, 'nom' => $e->nom, 'est_veterinaire' => $e->estVeterinaire()])]);
    }

    public function transferer(Request $request, SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->assurerQuiMene($session);
        $vers = $this->colleguesPossibles($session)->firstWhere('id', $request->integer('employe_id'));
        if ($vers === null) {
            throw ValidationException::withMessages(['employe_id' => 'Choisissez un collègue de la clinique qui peut prendre l’appel.']);
        }

        $session->forceFill(['transfert_vers_id' => $vers->id, 'transfert_demande_le' => now()])->save();
        $this->prevenir($vers, $session, 'demande');

        return $this->ressource($session);
    }

    public function annuler(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->assurerQuiMene($session);
        $vers = $session->transfertVers()->withoutGlobalScopes()->first();
        $session->forceFill(['transfert_vers_id' => null, 'transfert_demande_le' => null])->save();
        if ($vers !== null) {
            $this->prevenir($vers, $session, 'annule');
        }

        return $this->ressource($session);
    }

    /** Le collègue désigné prend l'appel : il le mène, l'autre poste se retire. */
    public function prendre(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->authorize('update', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        $moi = app(ContexteClinique::class)->employe();

        $pris = DB::transaction(function () use ($session, $moi): bool {
            $tenue = SessionTeleconsultation::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($tenue->transfert_vers_id === null) {
                throw ValidationException::withMessages(['transfert' => 'Ce transfert a été annulé.']);
            }
            if ($tenue->transfert_vers_id !== $moi?->id) {
                return false;
            }
            $tenue->forceFill(['mene_par_id' => $moi->id, 'transfert_vers_id' => null, 'transfert_demande_le' => null])->save();

            return true;
        });
        abort_unless($pris, 403, 'Cet appel est transféré à un autre collègue.');

        // Le poste qui menait l'appel se retire ; le client attend l'offre du nouveau
        TempsReel::diffuser(fn () => SignalVisio::dispatch($session->id, 'veterinaire', 'releve', ['par' => $moi->id, 'nom' => $moi->nom]));

        return $this->ressource($session->refresh());
    }

    private function assurerQuiMene(SessionTeleconsultation $session): void
    {
        $this->authorize('update', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        abort_unless($session->mene_par_id === app(ContexteClinique::class)->employe()?->id, 403, 'Seule la personne qui mène l’appel peut le transférer.');
    }

    /** @return Collection<int, Employe> */
    private function colleguesPossibles(SessionTeleconsultation $session): Collection
    {
        return Employe::withoutGlobalScopes()
            ->where('organisation_id', app(ContexteClinique::class)->organisationId())
            ->whereHas('cliniques', fn ($q) => $q->whereKey($session->clinique_id))
            ->where('acces_suspendu', false)
            ->whereKeyNot($session->mene_par_id)
            ->with(['role', 'derogations'])
            ->get()
            ->filter(fn (Employe $e): bool => in_array(DomainePermission::Planning->value, $this->permissions->pour($e), true))
            ->values();
    }

    private function prevenir(Employe $vers, SessionTeleconsultation $session, string $etat): void
    {
        $de = app(ContexteClinique::class)->employe()?->nom;
        $patient = $session->patient()->withoutGlobalScopes()->value('nom');
        TempsReel::diffuser(fn () => TransfertAppelVideo::dispatch($vers->user_id, ['etat' => $etat, 'session_id' => $session->id, 'patient' => $patient, 'de' => $de]));
    }

    private function ressource(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        return new SessionTeleconsultationResource($session->load(SessionTeleconsultationController::relations()));
    }
}
