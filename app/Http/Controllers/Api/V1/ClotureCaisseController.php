<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\PieceNumerotee;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\ApercuClotureCaisseRequest;
use App\Http\Requests\V1\Facturation\EnregistrerClotureCaisseRequest;
use App\Http\Resources\V1\ClotureCaisseResource;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Services\Facturation\ArreteDeCaisse;
use App\Services\Numeroteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôtures de caisse (Z) de la clinique du jeton. L'arrêté couvre
 * toutes les factures payées de la clinique qu'aucune clôture ne couvre
 * encore ; les totaux par moyen sont calculés côté serveur et tout
 * écart d'espèces doit être justifié. Les totaux TPE et TWINT ne sont
 * plus confirmés à la main (cases à cocher du prototype) : le
 * rapprochement sera automatique avec l'intégration Stripe.
 */
class ClotureCaisseController extends Controller
{
    public function __construct(private Numeroteur $numeroteur, private ArreteDeCaisse $arrete) {}

    /**
     * Ce que montre l'écran pendant la clôture (26.10.2026) : totaux de la
     * journée, fond, espèces attendues, billets comptés, écart et dépôt, du
     * même calcul que l'enregistrement. L'écran envoie les billets saisis,
     * il n'additionne rien.
     */
    public function apercu(ApercuClotureCaisseRequest $request): JsonResponse
    {
        $this->authorize('create', ClotureCaisse::class);
        $donnees = $request->validated();
        $clinique = app(ContexteClinique::class)->clinique();

        return response()->json(['data' => $this->arrete->calculer(
            $clinique,
            $this->arrete->factures($clinique),
            isset($donnees['fond']) ? (float) $donnees['fond'] : null,
            $donnees['comptage'] ?? [],
            null,
            isset($donnees['nouveau_fond']) ? (float) $donnees['nouveau_fond'] : null,
            isset($donnees['depot']) ? (float) $donnees['depot'] : null,
        )]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ClotureCaisse::class);

        $clotures = ClotureCaisse::query()
            ->withCount('factures')
            ->latest('arretee_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return ClotureCaisseResource::collection($clotures);
    }

    public function show(ClotureCaisse $cloture): ClotureCaisseResource
    {
        $this->authorize('viewAny', ClotureCaisse::class);

        return new ClotureCaisseResource($cloture->load('factures')->loadCount('factures'));
    }

    public function store(EnregistrerClotureCaisseRequest $request): ClotureCaisseResource
    {
        $this->authorize('create', ClotureCaisse::class);

        $donnees = $request->validated();
        $clinique = app(ContexteClinique::class)->clinique();

        // Tout sous verrou : deux clôtures en même temps ne prennent jamais
        // les mêmes factures, et le fond suit la clôture précédente
        $cloture = DB::transaction(function () use ($request, $donnees, $clinique): ClotureCaisse {
            $aClorer = $this->arrete->factures($clinique, verrouiller: true);

            if ($aClorer->isEmpty()) {
                throw ValidationException::withMessages([
                    'factures' => 'Aucun encaissement à clôturer depuis la dernière clôture.',
                ]);
            }

            // Le même calcul que l'aperçu montré à l'écran (26.10.2026)
            $arrete = $this->arrete->calculer(
                $clinique,
                $aClorer,
                (float) $donnees['fond'],
                array_key_exists('comptage', $donnees) && $donnees['comptage'] !== null ? $donnees['comptage'] : null,
                isset($donnees['especes_comptees']) ? (float) $donnees['especes_comptees'] : null,
                (float) $donnees['nouveau_fond'],
                isset($donnees['depot']) ? (float) $donnees['depot'] : null,
            );

            // Ce que l'écran montrait : un encaissement arrivé à un autre poste
            // pendant le comptage n'entre pas dans un Z qu'on n'a pas vu
            if (isset($donnees['encaissements_vus'])
                && ((int) $donnees['encaissements_vus'] !== $arrete['encaissements'] || abs((float) ($donnees['total_vu'] ?? 0) - $arrete['total']) >= 0.005)) {
                throw ValidationException::withMessages([
                    'encaissements' => 'Un encaissement est arrivé pendant la clôture : les totaux ont été mis à jour, vérifiez-les avant de valider.',
                ]);
            }

            // La même règle que l'aperçu : un écart, ou (paiements intégrés) une
            // carte ou un TWINT saisi à la main, qui pourrait cacher des espèces gardées
            $note = trim((string) ($donnees['note'] ?? ''));
            if ($arrete['motif_justification'] !== null && $note === '') {
                $saisiesALaMain = $arrete['sans_terminal'];
                throw ValidationException::withMessages(['note' => $arrete['motif_justification'] === 'ecart'
                    ? 'Écart de caisse : une justification est obligatoire dans la note.'
                    : $saisiesALaMain.($saisiesALaMain > 1 ? ' paiements par carte ou TWINT ont été saisis' : ' paiement par carte ou TWINT a été saisi').' sans terminal : justifiez-les dans la note.']);
            }

            if ($arrete['alerte_depot'] !== null) {
                throw ValidationException::withMessages(['depot' => $arrete['alerte_depot']]);
            }

            $cloture = ClotureCaisse::create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::ClotureCaisse),
                'arretee_le' => now(),
                'user_id' => $request->user()->id,
                'total_tpe' => $arrete['moyens'][MoyenPaiement::Tpe->value]['montant'],
                'total_twint' => $arrete['moyens'][MoyenPaiement::Twint->value]['montant'],
                'total_especes' => $arrete['moyens'][MoyenPaiement::Especes->value]['montant'],
                'fond' => $arrete['fond'],
                'especes_comptees' => $arrete['especes_comptees'],
                'ecart' => $arrete['ecart'],
                'depot' => $arrete['depot'],
                'nouveau_fond' => $arrete['nouveau_fond'],
                'note' => $note === '' ? null : $note,
            ]);
            Facture::whereIn('id', $aClorer->pluck('id'))->update(['cloture_caisse_id' => $cloture->id]);

            return $cloture;
        });

        return new ClotureCaisseResource($cloture->load('factures')->loadCount('factures'));
    }
}
