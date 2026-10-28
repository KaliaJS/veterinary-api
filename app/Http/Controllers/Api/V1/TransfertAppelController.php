<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Events\SignalVisio;
use App\Http\Controllers\Api\V1\Concerns\MeneLAppel;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SessionTeleconsultationResource;
use App\Models\Employe;
use App\Models\SessionTeleconsultation;
use App\Services\Tele\AvisTransfert;
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
    use MeneLAppel;

    public function __construct(private CalculateurPermissions $permissions, private AvisTransfert $avis) {}

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

        // Sous verrou : une prise au même instant passe avant (l'appel n'est
        // alors plus à moi) ou après ; un autre collègue qui attendait le sait
        $avant = DB::transaction(function () use ($session, $vers): ?int {
            $tenue = SessionTeleconsultation::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assurerMeneLAppel($tenue);
            $avant = $tenue->transfert_vers_id;
            $tenue->forceFill(['transfert_vers_id' => $vers->id, 'transfert_demande_le' => now()])->save();

            return $avant;
        });
        $session->refresh();
        if ($avant !== null && $avant !== $vers->id) {
            $this->prevenirEmploye($avant, $session, 'annule');
        }
        $this->avis->envoyer($vers->user_id, $session, 'demande');

        return $this->ressource($session);
    }

    public function annuler(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->assurerQuiMene($session);
        // Sous verrou : une prise au même instant passe avant, ou après l'annulation, jamais entre
        $vers = DB::transaction(function () use ($session): ?int {
            $tenue = SessionTeleconsultation::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assurerMeneLAppel($tenue);
            $vers = $tenue->transfert_vers_id;
            $tenue->forceFill(['transfert_vers_id' => null, 'transfert_demande_le' => null])->save();

            return $vers;
        });
        if ($vers !== null) {
            $this->prevenirEmploye($vers, $session, 'annule');
        }

        return $this->ressource($session->refresh());
    }

    /** Le collègue désigné prend l'appel : il le mène, l'autre poste se retire. */
    public function prendre(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        $this->authorize('update', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        $moi = app(ContexteClinique::class)->employe();

        $avant = DB::transaction(function () use ($session, $moi): ?int {
            $tenue = SessionTeleconsultation::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($tenue->transfert_vers_id === null) {
                // Déjà pris (sur un autre appareil), ou annulé entre-temps
                throw ValidationException::withMessages(['transfert' => $tenue->mene_par_id === $moi?->id ? 'Vous avez déjà pris cet appel.' : 'Ce transfert a été annulé.']);
            }
            abort_unless($tenue->transfert_vers_id === $moi?->id, 403, 'Cet appel est transféré à un autre collègue.');
            $avant = $tenue->mene_par_id;
            $tenue->forceFill(['mene_par_id' => $moi->id, 'transfert_vers_id' => null, 'transfert_demande_le' => null])->save();

            return $avant;
        });

        // Le poste qui menait l'appel se retire ; le client attend l'offre du nouveau
        TempsReel::diffuser(fn () => SignalVisio::dispatch($session->id, 'veterinaire', 'releve', ['par' => $moi->id, 'nom' => $moi->nom]));
        // Sur le canal des comptes aussi : l'ancien poste, même sans la relève
        // (page rechargée, WebSocket coupé), et mes autres appareils
        $pris = ['par' => $moi->id, 'nom' => $moi->nom];
        if ($avant !== null) {
            $this->prevenirEmploye($avant, $session, 'pris', $pris);
        }
        $this->avis->envoyer($moi->user_id, $session, 'pris', $pris);

        return $this->ressource($session->refresh());
    }

    private function assurerQuiMene(SessionTeleconsultation $session): void
    {
        $this->authorize('update', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        $this->assurerMeneLAppel($session);
    }

    /** @return Collection<int, Employe> */
    private function colleguesPossibles(SessionTeleconsultation $session): Collection
    {
        return Employe::withoutGlobalScopes()
            ->where('organisation_id', app(ContexteClinique::class)->organisationId())
            ->whereHas('cliniques', fn ($q) => $q->whereKey($session->clinique_id))
            ->where('acces_suspendu', false)
            // Contrat fini : il ne peut plus se connecter (le dernier jour compte encore)
            ->where(fn ($q) => $q->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', aujourdhui()))
            ->whereKeyNot($session->mene_par_id)
            ->with(['role', 'derogations'])
            ->get()
            ->filter(fn (Employe $e): bool => in_array(DomainePermission::Planning->value, $this->permissions->pour($e), true))
            ->values();
    }

    /** @param  array<string, mixed>  $plus */
    private function prevenirEmploye(int $employeId, SessionTeleconsultation $session, string $etat, array $plus = []): void
    {
        $userId = Employe::withoutGlobalScopes()->whereKey($employeId)->value('user_id');
        if ($userId !== null) {
            $this->avis->envoyer((int) $userId, $session, $etat, $plus);
        }
    }

    private function ressource(SessionTeleconsultation $session): SessionTeleconsultationResource
    {
        return new SessionTeleconsultationResource($session->load(SessionTeleconsultationController::relations()));
    }
}
