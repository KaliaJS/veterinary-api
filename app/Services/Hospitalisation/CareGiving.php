<?php

namespace App\Services\Hospitalisation;

use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\User;
use App\Services\GestionnaireStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Donner une prise, ou faire un soin (troisième et quatrième
 * vérifications, 03.11.2026). Tout se fait dans une transaction : le
 * séjour est relu sous verrou, comme à la sortie et à l'annulation ; la
 * prise aussi, pour que deux postes ne la donnent pas deux fois. Une sortie
 * passée entre-temps : la prise est notée « après la sortie, non
 * facturée », et le résultat le dit. Une prise se donne au plus 2 heures
 * avant son heure ; donnée en avance, elle garde l'heure réelle tapée si
 * elle est dans sa fenêtre et pas à venir.
 */
final class CareGiving
{
    /** Une prise se donne au plus 2 heures avant son heure */
    public const int ADVANCE_HOURS = 2;

    public function __construct(private GestionnaireStock $stock, private StaySheets $sheets) {}

    /**
     * @param  array<string, mixed>  $data  time, dose_amount
     * @return array{care: SejourSoin, stock: array<string, mixed>|null, unbilled: bool, unbilled_reason: string|null}
     */
    public function give(Sejour $sejour, int $careId, array $data, ?User $user): array
    {
        $result = DB::transaction(function () use ($sejour, $careId, $data, $user): array {
            $fresh = Sejour::withoutGlobalScopes()->whereKey($sejour->id)->lockForUpdate()->firstOrFail();
            // La sortie est passée pendant le geste : noté après la sortie, non facturé
            $isRaced = $fresh->sorti_le !== null && $sejour->sorti_le === null;
            $care = $this->noteable($fresh, $careId);
            // « Raced » : l'heure du geste lue comme avant la sortie, jamais après elle (sixième vérification)
            $when = $isRaced ? $this->gestureOf($sejour, $care, $data['time'] ?? null) : $this->gestureOf($fresh, $care, $data['time'] ?? null);
            if ($isRaced) {
                $when = ['at' => CarbonImmutable::instance($when['at'])->min($fresh->sorti_le), 'late' => true];
            }
            $care->update([
                'fait' => true,
                'fait_le' => $when['at'],
                'fait_par_id' => $user?->id,
                // Qui l'a donné, tel qu'aujourd'hui (03.11.2026)
                'done_by_name' => $this->sheets->authorName($user),
                'dose_amount' => $data['dose_amount'] ?? $care->dose_amount,
                'late_noted_at' => $when['late'] ? now() : null,
                // Le prix et la contenance du jour de la prise : la facture les lit ici
                'unit_price' => $care->produit?->prix,
                'content_per_unit' => $care->produit?->content_per_unit,
                // L'unité de dose aussi : une annulation ne mélange pas deux unités
                'dose_unit' => $care->produit?->dose_unit,
            ]);

            // Non facturée : la sortie est passée pendant le geste (« raced »), ou
            // le séjour était déjà sorti quand on l'a notée (« discharged »). La prise
            // le garde, lu sous le verrou du séjour (huitième vérification)
            $reason = $fresh->sorti_le === null ? null : ($isRaced ? 'raced' : 'discharged');
            $care->update(['is_unbilled' => $reason !== null]);

            return ['care' => $care, 'stock' => $this->takeStock($care, $fresh, $user), 'unbilled' => $reason !== null, 'unbilled_reason' => $reason];
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return $result;
    }

    /** La prise, verrouillée, si elle peut encore se noter (une fois, au plus 2 heures en avance) */
    public function noteable(Sejour $sejour, int $id): SejourSoin
    {
        /** @var SejourSoin $care */
        $care = SejourSoin::query()->where('sejour_id', $sejour->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        if (($care->fait && $care->cancelled_at === null) || $care->skipped_at !== null) {
            throw ValidationException::withMessages(['soin' => 'Ce soin est déjà enregistré.']);
        }
        // Annulée (donnée par erreur) : elle se note de nouveau, à la dose prescrite par
        // défaut ; l'annulation reste dans son historique (sixième vérification)
        if ($care->cancelled_at !== null) {
            $care->update([
                'fait' => false, 'fait_le' => null, 'fait_par_id' => null, 'done_by_name' => null, 'late_noted_at' => null,
                'cancelled_at' => null, 'cancelled_by_id' => null, 'cancelled_by_name' => null, 'cancelled_late' => false,
                'dose_amount' => $care->treatment->dose_amount ?? $care->dose_amount,
                'unit_price' => null, 'content_per_unit' => null, 'dose_unit' => null, 'opening_number' => null, 'is_unbilled' => false,
            ]);
        }
        if ($this->planned($sejour, $care)->gt(now()->addHours(self::ADVANCE_HOURS))) {
            throw ValidationException::withMessages(['soin' => 'Ce soin est prévu plus tard : il se donne au plus 2 heures en avance.']);
        }

        return $care;
    }

    /**
     * L'heure réelle du geste, sur la feuille du soin ; sur une feuille close,
     * demandée. Donnée en avance (feuille suivante) : maintenant, ou l'heure
     * tapée si elle est dans la fenêtre de 2 heures et pas à venir.
     *
     * @return array{day: string, minute: int, sheet_day: string, at: CarbonImmutable, late: bool}|array{at: CarbonImmutable, late: bool}
     */
    public function gestureOf(Sejour $sejour, SejourSoin $care, ?int $time): array
    {
        $sheet = StaySheets::sheetDayOf($care->jour->toDateString(), $care->prevu_a);
        $today = $this->sheets->today($sejour);
        if ($sheet > $today) {
            $isDischarged = $sejour->sorti_le !== null;
            if ($time === null) {
                // Après la sortie, l'heure du geste est demandée (cinquième vérification)
                return $this->sheets->gestureTime($sejour, null, null, $isDischarged);
            }
            // L'heure tapée : la dernière fois qu'il a été cette heure-là
            $local = $this->sheets->local($sejour);
            $at = $this->sheets->instant($sejour, $local['day'], $time);
            if ($at->gt(now()->addMinute())) {
                $at = $this->sheets->instant($sejour, CarbonImmutable::parse($local['day'])->subDay()->toDateString(), $time);
            }
            if ($at->lt($this->planned($sejour, $care)->subHours(self::ADVANCE_HOURS))) {
                throw ValidationException::withMessages(['time' => 'Cette heure est plus de 2 heures avant la prise : elle ne se donne pas si tôt.']);
            }
            // Donnée en avance, notée après la sortie : comparée à la sortie, note tardive
            if ($isDischarged && $at->gt($sejour->sorti_le)) {
                throw ValidationException::withMessages(['time' => 'L’animal était déjà sorti à cette heure.']);
            }

            return ['at' => $at, 'late' => $isDischarged];
        }

        return $this->sheets->gestureTime($sejour, $sheet, $time, $sheet < $today || $sejour->sorti_le !== null);
    }

    private function planned(Sejour $sejour, SejourSoin $care): CarbonImmutable
    {
        return $this->sheets->instant($sejour, $care->jour->toDateString(), $care->prevu_a);
    }

    /**
     * Le stock d'un soin fait. Une prise d'un traitement sort sa dose, en
     * fraction d'unité ; un soin avec un produit (un pansement) sort une
     * unité, comme avant. Un stupéfiant ne sort que par le registre.
     *
     * @return array<string, mixed>|null
     */
    private function takeStock(SejourSoin $care, Sejour $sejour, ?User $user): ?array
    {
        $product = $care->produit;
        if ($product === null) {
            return null;
        }
        $label = 'Soin d\'hospitalisation : '.$care->acte;
        $isNarcotic = (bool) $product->est_stupefiant;

        if ($care->treatment_id !== null) {
            // Au-delà du stock disponible, refusée, sauf la dose prescrite (rupture partielle)
            $dose = $care->dose_amount !== null ? $this->stock->takeDose($product, (float) $care->dose_amount, $label, $user, $care, prescribed: $care->treatment?->dose_amount) : null;
            if ($dose === null) {
                // Contenance ou dose inconnue : rien ne sort, l'écran le dit
                return ['sorti' => false, 'rupture' => false, 'registre' => false, 'dose_inconnue' => true];
            }
            // De quel flacon entamé vient la prise : seul celui-là peut la reprendre ; et la
            // contenance que takeDose a vraiment utilisée, lue sous le verrou (neuvième vérification)
            // Unité et prix aussi : ceux lus sous le verrou de l'article (dixième vérification)
            $care->update([
                'opening_number' => $dose['opening'],
                'content_per_unit' => $dose['content'] ?? $care->content_per_unit,
                'dose_unit' => $dose['dose_unit'] ?? $care->dose_unit,
                'unit_price' => $dose['price'] ?? $care->unit_price,
            ]);

            // Ce qui est vraiment sorti, et ce qui était demandé (rupture partielle)
            return ['sorti' => $dose['dose'] > 0, 'rupture' => $dose['rupture'], 'registre' => $dose['registre'], 'dose' => $dose['dose'], 'dose_requested' => (float) $care->dose_amount, 'dose_unit' => $care->dose_unit ?? $product->dose_unit, 'unites_ouvertes' => $dose['units'], 'non_facturee' => $sejour->sorti_le !== null];
        }

        // Écart réellement appliqué : 0 = produit en rupture dans cette
        // clinique (le soin reste fait). Un stupéfiant : 0 sans rupture, la
        // dose reste à inscrire au registre (24.10.2026).
        $taken = $this->stock->sortir($product, 1, $label, $user, $care);

        return ['sorti' => $taken === -1, 'rupture' => $taken === 0 && ! $isNarcotic, 'registre' => $isNarcotic];
    }
}
