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
 * Rappels aux propriétaires (28.10.2026) : réglages de la clinique, l'écran
 * « Rappels » (01.11.2026, choix A de Jim : prochains, envois réels, ce qui
 * attend l'équipe, aperçu des messages) et « Rappeler le propriétaire »
 * depuis le carnet.
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
        $correspondance = ['delai_jours' => 'rappels_delai_jours', 'vaccins' => 'rappels_vaccins', 'controles' => 'rappels_controles',
            'rendez_vous' => 'rappels_rendez_vous', 'relance_vaccin_retard' => 'relance_vaccin_retard', 'relance_devis' => 'relance_devis',
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
            ->map(fn (RappelEnvoye $rappel): array => $this->envoye($rappel));

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

    /**
     * Ce qu'aucun message automatique ne règle et qui attend l'équipe
     * (01.11.2026) : un vaccin toujours en retard malgré la relance, un
     * renouvellement d'ordonnance demandé, un devis resté sans réponse.
     */
    public function aFaire(Request $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        $request->validate([
            'type' => ['nullable', 'in:vaccin,ordonnance,devis'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $clinique = app(ContexteClinique::class)->clinique();

        // Page par page, filtré par type (Jim, 11.11.2026 : tout ce qui est à faire, au fil du défilement)
        $lignes = collect($this->rappels->aFaire($clinique))
            ->when($request->input('type'), fn ($lignes, string $type) => $lignes->where('type', $type))
            ->values();
        $parPage = $request->integer('per_page') ?: 25;
        $page = $request->integer('page') ?: 1;

        return response()->json([
            'data' => $lignes->forPage($page, $parPage)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($lignes->count() / $parPage)), 'per_page' => $parPage, 'total' => $lignes->count()],
        ]);
    }

    /**
     * Ce qui est parti, le plus récent d'abord, page par page (11.11.2026) ;
     * « vaccin » compte aussi ses relances de retard.
     */
    public function envoyes(Request $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        $request->validate([
            'type' => ['nullable', 'in:vaccin,controle,rdv,noshow,devis'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $clinique = app(ContexteClinique::class)->clinique();
        $type = $request->input('type');

        $page = RappelEnvoye::query()
            ->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes(), 'client' => fn ($client) => $client->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)
            ->when($type, fn ($requete) => $requete->whereIn('objet', $type === 'vaccin' ? ['vaccin', 'vaccin_retard'] : [$type]))
            ->orderByRaw('coalesce(envoye_le, updated_at) desc')->orderByDesc('id')
            ->paginate($request->integer('per_page') ?: 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (RappelEnvoye $rappel): array => $this->envoye($rappel))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Un rappel parti, tel que l'écran le montre */
    private function envoye(RappelEnvoye $rappel): array
    {
        return [
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
        ];
    }

    /**
     * Le message tel que le client le reçoit (01.11.2026) : l'e-mail rendu et
     * le SMS, pour un animal d'exemple, avec l'en-tête de la clinique.
     */
    public function apercu(string $objet): JsonResponse
    {
        Gate::authorize(DomainePermission::Patients->value);
        abort_unless(in_array($objet, ['vaccin', 'vaccin_retard', 'controle', 'rdv', 'noshow', 'devis'], true), 404);

        return response()->json(['data' => $this->rappels->apercu(app(ContexteClinique::class)->clinique(), $objet)]);
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

        return response()->json(['data' => ['statut' => $resultat['statut'], 'adresse' => $resultat['adresse'], 'manque' => $resultat['manque'] ?? null, 'envoye_le' => $resultat['rappel']?->envoye_le?->toIso8601String()]]);
    }
}
