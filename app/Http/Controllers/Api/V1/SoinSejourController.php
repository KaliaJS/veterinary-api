<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\PlanifierSoinRequest;
use App\Http\Resources\V1\SejourSoinResource;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Services\Hospitalisation\CareCancellation;
use App\Services\Hospitalisation\CareGiving;
use App\Services\Hospitalisation\StaySheets;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan de soins d'un séjour — enfants du dossier, toujours résolus via
 * le séjour. « Soin fait » est irréversible et sort le stock de la
 * clinique du séjour quand un produit est lié : effet système (via
 * GestionnaireStock, mouvement journalisé), sans exiger le domaine
 * stocks. Une rupture n'empêche pas le soin — elle est signalée.
 *
 * La ronde (03.11.2026) : une prise d'un traitement sort sa dose, en
 * fraction d'unité ; un geste se note à son heure réelle, et sur une
 * feuille close comme note tardive (décision de Jim, sans limite).
 */
class SoinSejourController extends Controller
{
    /** Ce qu'un geste « donné » accepte : l'heure réelle, la dose vraiment donnée */
    private const array GIVE_RULES = [
        'time' => ['sometimes', 'nullable', 'integer', 'between:0,1439'],
        'dose_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100000'],
    ];

    public function __construct(private StaySheets $sheets, private CareCancellation $cancellation, private CareGiving $giving) {}

    public function store(PlanifierSoinRequest $request, Sejour $sejour): SejourSoinResource
    {
        $this->authorize('update', $sejour);

        $data = $request->validated();
        // Le jour de la feuille (03.11.2026) : avant 06:00, c'est le lendemain au
        // calendrier. Sans jour donné, la feuille en cours (la nuit, celle de la veille)
        if (! isset($data['jour'])) {
            $sheet = $data['sheet_day'] ?? $this->sheets->today($sejour);
            $data['jour'] = $data['prevu_a'] < StaySheets::START
                ? CarbonImmutable::parse($sheet)->addDay()->toDateString()
                : $sheet;
        }
        unset($data['sheet_day']);
        $jour = $data['jour'];
        // Après la sortie (décision de Jim, « sans limite ») : seulement un soin
        // oublié, sans produit, pendant le séjour ; rien qui touche à l'argent
        if ($sejour->estSorti()) {
            if (! empty($data['produit_id'])) {
                throw ValidationException::withMessages(['produit_id' => 'Facture déjà émise : après la sortie, un soin oublié se note sans produit.']);
            }
            if ($this->sheets->instant($sejour, $jour, (int) $data['prevu_a'])->gt($sejour->sorti_le)) {
                throw ValidationException::withMessages(['sejour' => 'Un séjour sorti ne se modifie plus.']);
            }
        }
        // Un soin ajouté sur une feuille close est une note tardive ; les jours à venir se planifient
        $isLate = StaySheets::sheetDayOf($jour, (int) $data['prevu_a']) < $this->sheets->today($sejour);

        $soin = $sejour->soins()->create([...$data, 'jour' => $jour, 'late_noted_at' => $isLate ? now() : null])->load('produit');
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return new SejourSoinResource($soin);
    }

    /**
     * « Non donné » ou « non fait » (03.11.2026), avec sa raison et son
     * heure : au nom de qui le note. Rien ne sort du stock.
     */
    public function skip(Request $request, Sejour $sejour, int $soin): SejourSoinResource
    {
        $this->authorize('update', $sejour);
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'time' => ['sometimes', 'nullable', 'integer', 'between:0,1439'],
        ]);

        $soin = DB::transaction(function () use ($request, $sejour, $soin, $data): SejourSoin {
            $soin = $this->giving->noteable($sejour, $soin);
            $when = $this->giving->gestureOf($sejour, $soin, $data['time'] ?? null);
            $soin->update([
                'skipped_at' => $when['at'],
                'skipped_by_id' => $request->user()?->id,
                'skipped_by_name' => $this->sheets->authorName($request->user()),
                'skip_reason' => $data['reason'] ?? null,
                'late_noted_at' => $when['late'] ? now() : null,
            ]);

            return $soin;
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return new SejourSoinResource($soin->load('produit'));
    }

    public function faire(Request $request, Sejour $sejour, int $soin): SejourSoinResource
    {
        $this->authorize('update', $sejour);
        $data = $request->validate(self::GIVE_RULES);

        return $this->give($request, $sejour, $soin, $data);
    }

    /**
     * Une prise prévue d'un traitement, pas encore en base (la feuille
     * suivante, avant 06:00), donnée en avance dans sa fenêtre de 2 heures :
     * elle est créée sur sa feuille (sans doublon, l'index unique compte),
     * puis donnée (troisième vérification, 03.11.2026).
     */
    public function giveTreatmentDose(Request $request, Sejour $sejour, int $traitement): SejourSoinResource
    {
        $this->authorize('update', $sejour);
        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'minute' => ['required', 'integer', 'between:0,1439'],
            ...self::GIVE_RULES,
        ]);

        // Créée et donnée ensemble, le traitement relu sous verrou : refusée (trop
        // tôt, traitement arrêté entre-temps), elle n'existe pas
        return DB::transaction(function () use ($request, $sejour, $traitement, $data): SejourSoinResource {
            /** @var StayTreatment $treatment */
            $treatment = $sejour->treatments()->whereKey($traitement)->lockForUpdate()->firstOrFail();
            if ($treatment->ends_at !== null && $this->sheets->instant($sejour, $data['day'], (int) $data['minute'])->gte($treatment->ends_at)) {
                throw ValidationException::withMessages(['traitement' => 'Traitement arrêté : cette prise n’est plus prévue.']);
            }
            $sheet = StaySheets::sheetDayOf($data['day'], (int) $data['minute']);
            $isScheduled = collect($this->sheets->doses($sejour, $treatment, $sheet))
                ->contains(fn (array $dose): bool => $dose['day'] === $data['day'] && $dose['minute'] === (int) $data['minute']);
            if (! $isScheduled) {
                throw ValidationException::withMessages(['minute' => 'Cette heure n’est pas une prise de ce traitement.']);
            }

            $care = SejourSoin::query()->createOrFirst(
                ['treatment_id' => $treatment->id, 'jour' => CarbonImmutable::parse($data['day']), 'prevu_a' => (int) $data['minute']],
                ['sejour_id' => $sejour->id, 'acte' => $treatment->label, 'dose' => $treatment->dose, 'dose_amount' => $treatment->dose_amount, 'produit_id' => $treatment->product_id],
            );

            return $this->give($request, $sejour, $care->id, $data);
        });
    }

    /**
     * Donné, ou fait : à son heure réelle, à la dose donnée, sous verrou
     * (voir CareGiving). La réponse dit si la prise n'est pas facturée.
     *
     * @param  array<string, mixed>  $data
     */
    private function give(Request $request, Sejour $sejour, int $soin, array $data): SejourSoinResource
    {
        $result = $this->giving->give($sejour, $soin, $data, $request->user());
        $resource = new SejourSoinResource($result['care']->load('produit'));
        $resource->additional([
            ...($result['stock'] !== null ? ['stock' => $result['stock']] : []),
            'unbilled' => $result['unbilled'],
            // « raced » : sortie au même moment ; « discharged » : séjour déjà sorti
            'unbilled_reason' => $result['unbilled_reason'],
        ]);

        return $resource;
    }

    /** Annuler une prise ou un soin donné par erreur (voir CareCancellation) */
    public function cancel(Request $request, Sejour $sejour, int $soin): SejourSoinResource
    {
        $this->authorize('update', $sejour);

        $result = $this->cancellation->cancel($sejour, $soin, $request->user());

        return (new SejourSoinResource($result['care']->load('produit')))->additional(['stock' => $result['stock']]);
    }
}
