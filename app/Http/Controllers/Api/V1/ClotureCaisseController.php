<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\PieceNumerotee;
use App\Enums\StatutFacture;
use App\Enums\StatutPaiement;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\EnregistrerClotureCaisseRequest;
use App\Http\Resources\V1\ClotureCaisseResource;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Services\Numeroteur;
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
    public function __construct(private Numeroteur $numeroteur) {}

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
            $aClorer = Facture::query()
                ->where('statut', StatutFacture::Payee)
                ->whereNull('cloture_caisse_id')
                ->where('clinique_id', $clinique->id)
                ->with('lignes')
                ->lockForUpdate()
                ->get();

            if ($aClorer->isEmpty()) {
                throw ValidationException::withMessages([
                    'factures' => 'Aucun encaissement à clôturer depuis la dernière clôture.',
                ]);
            }

            $totalPar = fn (MoyenPaiement $moyen): float => round(
                (float) $aClorer
                    ->filter(fn (Facture $facture): bool => $facture->moyen_paiement === $moyen)
                    // Ce qui est passé à la caisse : l'acompte en ligne n'y est pas
                    ->sum(fn (Facture $facture): float => $facture->resteAPayer()),
                2,
            );

            $tpe = $totalPar(MoyenPaiement::Tpe);
            $twint = $totalPar(MoyenPaiement::Twint);
            $especes = $totalPar(MoyenPaiement::Especes);

            // Le fond de départ est celui que la clôture précédente a laissé
            // (23.10.2026 : jamais déclaré librement) ; le premier jour, celui saisi
            $precedente = ClotureCaisse::query()->orderByDesc('arretee_le')->orderByDesc('id')->first();
            $fond = $precedente !== null ? round((float) $precedente->nouveau_fond, 2) : round((float) $donnees['fond'], 2);
            $comptees = round((float) $donnees['especes_comptees'], 2);
            $ecart = round($comptees - ($fond + $especes), 2);
            $note = trim((string) ($donnees['note'] ?? ''));

            if (abs($ecart) >= 0.005 && $note === '') {
                throw ValidationException::withMessages([
                    'note' => 'Écart de caisse : une justification est obligatoire dans la note.',
                ]);
            }

            // Paiements intégrés : une carte ou un TWINT saisi à la main (sans
            // terminal) se justifie, il pourrait cacher des espèces gardées
            $saisiesALaMain = $clinique->encaisseAvecVeterinary()
                ? $aClorer->filter(fn (Facture $facture): bool => in_array($facture->moyen_paiement, [MoyenPaiement::Tpe, MoyenPaiement::Twint], true)
                    && $facture->paiements()->whereIn('statut', StatutPaiement::encaisses())->doesntExist())->count()
                : 0;
            if ($saisiesALaMain > 0 && $note === '') {
                throw ValidationException::withMessages([
                    'note' => $saisiesALaMain.($saisiesALaMain > 1 ? ' paiements par carte ou TWINT ont été saisis' : ' paiement par carte ou TWINT a été saisi').' sans terminal : justifiez-les dans la note.',
                ]);
            }

            $nouveauFond = round((float) $donnees['nouveau_fond'], 2);
            $depot = isset($donnees['depot']) ? round((float) $donnees['depot'], 2) : max(0, round($comptees - $nouveauFond, 2));
            if ($depot > $comptees + 0.005) {
                throw ValidationException::withMessages(['depot' => 'Le dépôt ne dépasse jamais les espèces comptées.']);
            }

            $cloture = ClotureCaisse::create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::ClotureCaisse),
                'arretee_le' => now(),
                'user_id' => $request->user()->id,
                'total_tpe' => $tpe,
                'total_twint' => $twint,
                'total_especes' => $especes,
                'fond' => $fond,
                'especes_comptees' => $comptees,
                'ecart' => $ecart,
                'depot' => $depot,
                'nouveau_fond' => $nouveauFond,
                'note' => $note === '' ? null : $note,
            ]);

            Facture::whereIn('id', $aClorer->pluck('id'))->update(['cloture_caisse_id' => $cloture->id]);

            return $cloture;
        });

        return new ClotureCaisseResource($cloture->load('factures')->loadCount('factures'));
    }
}
