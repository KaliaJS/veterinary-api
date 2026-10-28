<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Rappels\ModifierReglagesRappelsRequest;
use App\Models\Patient;
use App\Models\RappelEnvoye;
use App\Models\Vaccination;
use App\Services\Rappels\RappelsProprietaires;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Rappels aux propriétaires (28.10.2026) : réglages de la clinique, la
 * fenêtre « Rappels automatiques » (prochains et envois réels) et
 * « Rappeler le propriétaire » depuis le carnet.
 */
class RappelsController extends Controller
{
    public function __construct(private RappelsProprietaires $rappels) {}

    public function reglages(): JsonResponse
    {
        return response()->json(['data' => RappelsProprietaires::reglages(app(ContexteClinique::class)->clinique())]);
    }

    public function modifierReglages(ModifierReglagesRappelsRequest $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Organisation->value);
        $clinique = app(ContexteClinique::class)->clinique();
        $correspondance = ['email' => 'rappels_email', 'sms' => 'rappels_sms', 'delai_jours' => 'rappels_delai_jours', 'vaccins' => 'rappels_vaccins', 'controles' => 'rappels_controles',
            'relance_noshow' => 'relance_noshow', 'messages_recus' => 'messages_recus', 'messages_reponses_seulement' => 'messages_reponses_seulement'];
        foreach ($request->validated() as $cle => $valeur) {
            $clinique->{$correspondance[$cle]} = $valeur;
        }
        $clinique->save();

        return response()->json(['data' => RappelsProprietaires::reglages($clinique)]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        $clinique = app(ContexteClinique::class)->clinique();

        $envoyes = RappelEnvoye::query()->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes(), 'client' => fn ($client) => $client->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)->latest('id')->limit(50)->get()
            ->map(fn (RappelEnvoye $rappel): array => [
                'id' => $rappel->id,
                'objet' => $rappel->objet,
                'libelle' => $rappel->libelle,
                'patient' => ['id' => $rappel->patient_id, 'nom' => $rappel->patient?->nom],
                'proprietaire' => $rappel->client ? RappelsProprietaires::nomDe($rappel->client) : null,
                'echeance_le' => $rappel->echeance_le->toDateString(),
                'canal' => $rappel->canal,
                'statut' => $rappel->statut,
                'manuel' => $rappel->manuel,
                'envoye_le' => $rappel->envoye_le?->toIso8601String(),
                'tente_le' => $rappel->updated_at?->toIso8601String(),
            ]);

        $depuis = now()->subDays(30);
        $aVenir = $this->rappels->aVenir($clinique);

        return response()->json(['data' => [
            'reglages' => RappelsProprietaires::reglages($clinique),
            // Compteurs des 30 derniers jours, comptés par le serveur (la liste s'arrête à 50)
            'compteurs' => [
                // Ce qui partira vraiment : les lignes bloquées n'y sont pas
                'a_venir' => count(array_filter($aVenir, fn (array $ligne): bool => $ligne['bloque'] === null)),
                'envoyes' => RappelEnvoye::query()->where('clinique_id', $clinique->id)->where('statut', 'envoye')->where('envoye_le', '>=', $depuis)->count(),
                'echecs' => RappelEnvoye::query()->where('clinique_id', $clinique->id)->where('statut', 'echec')->where('updated_at', '>=', $depuis)->count(),
            ],
            'a_venir' => $aVenir,
            'envoyes' => $envoyes,
        ]]);
    }

    /** « Rappeler le propriétaire » depuis le carnet : part tout de suite, gardé. */
    public function vaccination(Request $request, Patient $patient, Vaccination $vaccination): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        abort_if($vaccination->patient_id !== $patient->id, 404);

        $resultat = $this->rappels->rappelerMaintenant(app(ContexteClinique::class)->clinique(), $vaccination, $request->user());
        if ($resultat['statut'] === 'refuse') {
            throw ValidationException::withMessages(['rappel' => $resultat['raison']]);
        }
        if ($resultat['statut'] === 'echec') {
            throw ValidationException::withMessages(['rappel' => $resultat['raison']]);
        }

        return response()->json(['data' => ['statut' => $resultat['statut'], 'adresse' => $resultat['adresse'], 'envoye_le' => $resultat['rappel']?->envoye_le?->toIso8601String()]]);
    }
}
