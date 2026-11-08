<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategorieProduit;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\PrescribeTreatmentRequest;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Services\Hospitalisation\StaySheets;
use App\Services\Isabv\Declarations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Les traitements d'un séjour (03.11.2026). Prescrit, un traitement place
 * aussitôt les prises qui restent sur la feuille du jour, et les jours à
 * venir les montrent prévues. Arrêté, ses prises encore à venir
 * disparaissent ; ce qui a été donné ou noté reste.
 */
class StayTreatmentController extends Controller
{
    public function __construct(private StaySheets $sheets) {}

    public function store(PrescribeTreatmentRequest $request, Sejour $sejour): JsonResponse
    {
        $this->authorize('update', $sejour);
        if ($sejour->estSorti()) {
            throw ValidationException::withMessages(['sejour' => 'Un séjour sorti ne se modifie plus.']);
        }

        $data = $request->validated();
        $warning = $this->completeProduct($data);
        unset($data['product_content'], $data['product_dose_unit']);

        $treatment = DB::transaction(function () use ($request, $sejour, $data): StayTreatment {
            $treatment = StayTreatment::create([
                ...$data,
                'sejour_id' => $sejour->id,
                'starts_at' => now(),
                'created_by_id' => $request->user()?->id,
                'created_by_name' => $this->sheets->authorName($request->user()),
            ]);
            $this->sheets->open($sejour);
            $this->sheets->materialize($sejour, $this->sheets->today($sejour), $treatment);
            // SI ABV (08.11.2026) : un antibiotique utilisé pendant le séjour se déclare, à compléter ;
            // la fréquence vient de ses heures de prise
            if ($treatment->product_id !== null) {
                app(Declarations::class)->forProductUse($treatment, $sejour->clinique_id, $sejour->patient, $treatment->product, Declarations::responsibleFor($sejour->veterinaire_id),
                    $this->sheets->today($sejour), $treatment->dose_amount !== null ? ['product_dose' => (float) $treatment->dose_amount] : [], $treatment->times ? (float) count($treatment->times) : null);
            }

            return $treatment;
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return response()->json(['data' => self::data($treatment->load('product')), 'warning' => $warning], 201);
    }

    public function stop(Request $request, Sejour $sejour, int $traitement): JsonResponse
    {
        $this->authorize('update', $sejour);
        // Sous verrou : une prise donnée en avance au même instant attend, puis voit l'arrêt
        $treatment = DB::transaction(function () use ($request, $sejour, $traitement): StayTreatment {
            /** @var StayTreatment $treatment */
            $treatment = $sejour->treatments()->whereKey($traitement)->lockForUpdate()->firstOrFail();
            if ($treatment->ends_at !== null) {
                throw ValidationException::withMessages(['traitement' => 'Ce traitement est déjà arrêté.']);
            }
            // Puis le séjour, dans l'ordre de « donner » (traitement, séjour, prise) :
            // un geste en cours finit avant que l'arrêt lise les prises
            Sejour::withoutGlobalScopes()->whereKey($sejour->id)->lockForUpdate()->firstOrFail();
            $treatment->update(['ends_at' => now(), 'stopped_by_name' => $this->sheets->authorName($request->user())]);
            // Les prises encore à venir, ni données ni notées, disparaissent : lues
            // sous verrou, et l'effacement revérifie qu'aucune n'a été donnée entre-temps
            $ids = SejourSoin::query()->where('treatment_id', $treatment->id)->where('fait', false)->whereNull('skipped_at')->lockForUpdate()->get()
                ->filter(fn (SejourSoin $soin): bool => $this->sheets->instant($sejour, $soin->jour->toDateString(), $soin->prevu_a)->gt(now()))
                ->modelKeys();
            SejourSoin::query()->whereKey($ids)->where('fait', false)->whereNull('skipped_at')->delete();
            // SI ABV : arrêté, le traitement dit ses jours (de la première prise à l'arrêt)
            app(Declarations::class)->treatmentStopped($treatment);

            return $treatment;
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return response()->json(['data' => self::data($treatment->load('product'))]);
    }

    /**
     * Les médicaments du stock, à choisir dans une liste (03.11.2026) : leur
     * unité de dose et leur contenance. Le droit Stocks est demandé ; sans
     * lui, l'écran prévient que rien ne sortira du stock.
     */
    public function products(): JsonResponse
    {
        abort_unless(Gate::allows(DomainePermission::Stocks->value), 403);

        return response()->json(['data' => Produit::query()
            ->whereIn('categorie', [CategorieProduit::Medicament, CategorieProduit::Antiparasitaire, CategorieProduit::Phytotherapie])
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p): array => [
                'id' => $p->id,
                'name' => $p->nom,
                'unit' => $p->unite,
                'dose_unit' => $p->dose_unit,
                'content_per_unit' => $p->content_per_unit,
                'is_narcotic' => (bool) $p->est_stupefiant,
            ])->values()->all()]);
    }

    /**
     * Un produit qui ne dit pas encore ce que contient une unité : la
     * prescription le complète, sinon la dose ne pourrait pas sortir du stock.
     * Écrire au catalogue demande le droit Stocks (deuxième vérification) :
     * sans lui, la prescription garde la dose et l'écran le dit.
     *
     * @param  array<string, mixed>  $data
     */
    private function completeProduct(array $data): ?string
    {
        if (empty($data['product_id'])) {
            return null;
        }
        $product = Produit::query()->findOrFail($data['product_id']);
        if ((float) $product->content_per_unit > 0 && $product->dose_unit) {
            return null;
        }
        if (! Gate::allows(DomainePermission::Stocks->value)) {
            return 'Sans le droit Stocks, la contenance n’est pas enregistrée au catalogue : les prises ne sortiront pas du stock.';
        }
        if (empty($data['product_content'])) {
            throw ValidationException::withMessages(['product_content' => 'Que contient une unité de « '.$product->nom.' » (par exemple 20 ml) ? Sans cela, la dose ne peut pas sortir du stock.']);
        }
        $product->forceFill(['content_per_unit' => $data['product_content'], 'dose_unit' => $data['product_dose_unit']])->save();

        return null;
    }

    /** @return array<string, mixed> */
    public static function data(StayTreatment $treatment): array
    {
        return [
            'id' => $treatment->id,
            'label' => $treatment->label,
            'dose' => $treatment->dose,
            'dose_amount' => $treatment->dose_amount,
            'dose_unit' => $treatment->product?->dose_unit,
            'times' => $treatment->minutes(),
            'product' => $treatment->product === null ? null : ['id' => $treatment->product->id, 'name' => $treatment->product->nom],
            'is_active' => $treatment->ends_at === null,
            'by' => $treatment->created_by_name,
            'stopped_by' => $treatment->stopped_by_name,
        ];
    }
}
