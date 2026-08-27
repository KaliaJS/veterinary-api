<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\PieceNumerotee;
use App\Enums\StatutFacture;
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
 * encore ; les totaux par moyen sont calculés côté serveur, le
 * rapprochement TPE/TWINT est exigé quand ces moyens ont encaissé, et
 * tout écart d'espèces doit être justifié.
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

        $aClorer = Facture::query()
            ->where('statut', StatutFacture::Payee)
            ->whereNull('cloture_caisse_id')
            ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
            ->with('lignes')
            ->get();

        if ($aClorer->isEmpty()) {
            throw ValidationException::withMessages([
                'factures' => 'Aucun encaissement à clôturer depuis la dernière clôture.',
            ]);
        }

        $totalPar = fn (MoyenPaiement $moyen): float => round(
            (float) $aClorer
                ->filter(fn (Facture $facture): bool => $facture->moyen_paiement === $moyen)
                ->sum(fn (Facture $facture): float => $facture->totalTtc()),
            2,
        );

        $tpe = $totalPar(MoyenPaiement::Tpe);
        $twint = $totalPar(MoyenPaiement::Twint);
        $especes = $totalPar(MoyenPaiement::Especes);

        if ($tpe > 0 && ! ($donnees['tpe_rapproche'] ?? false)) {
            throw ValidationException::withMessages([
                'tpe_rapproche' => 'Rapprochez d’abord le total TPE avec le ticket de clôture du terminal.',
            ]);
        }

        if ($twint > 0 && ! ($donnees['twint_rapproche'] ?? false)) {
            throw ValidationException::withMessages([
                'twint_rapproche' => 'Rapprochez d’abord le total TWINT.',
            ]);
        }

        $fond = round((float) $donnees['fond'], 2);
        $comptees = round((float) $donnees['especes_comptees'], 2);
        $ecart = round($comptees - ($fond + $especes), 2);
        $note = trim((string) ($donnees['note'] ?? ''));

        if (abs($ecart) >= 0.005 && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Écart de caisse — une justification est obligatoire dans la note.',
            ]);
        }

        $nouveauFond = round((float) $donnees['nouveau_fond'], 2);

        $cloture = DB::transaction(function () use ($request, $donnees, $aClorer, $tpe, $twint, $especes, $fond, $comptees, $ecart, $note, $nouveauFond): ClotureCaisse {
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
                'depot' => isset($donnees['depot'])
                    ? round((float) $donnees['depot'], 2)
                    : max(0, round($comptees - $nouveauFond, 2)),
                'nouveau_fond' => $nouveauFond,
                'note' => $note === '' ? null : $note,
            ]);

            Facture::whereIn('id', $aClorer->pluck('id'))->update(['cloture_caisse_id' => $cloture->id]);

            return $cloture;
        });

        return new ClotureCaisseResource($cloture->load('factures')->loadCount('factures'));
    }
}
