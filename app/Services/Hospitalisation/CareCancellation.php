<?php

namespace App\Services\Hospitalisation;

use App\Models\MouvementStock;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Models\User;
use App\Services\GestionnaireStock;
use App\Services\Isabv\Declarations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Annuler une prise ou un soin donné par erreur (deuxième et troisième
 * vérifications, 03.11.2026). Tout se vérifie dans la transaction, sous
 * verrou : le séjour n'est ni sorti ni facturé, la prise est donnée et pas
 * encore annulée. La prise reste visible, barrée « annulée par … » ; ce
 * qu'elle avait sorti revient dans le flacon entamé d'où elle vient, s'il
 * est encore là, sans dépasser la contenance (cinquième vérification) ;
 * la facture ne la compte plus. Sur une feuille close : « après coup ».
 */
final class CareCancellation
{
    public function __construct(private GestionnaireStock $stock, private StaySheets $sheets) {}

    /**
     * @return array{care: SejourSoin, stock: array<string, mixed>|null} ce qui est vraiment revenu au stock
     */
    public function cancel(Sejour $sejour, int $careId, ?User $user): array
    {
        $result = DB::transaction(function () use ($sejour, $careId, $user): array {
            $fresh = Sejour::withoutGlobalScopes()->whereKey($sejour->id)->lockForUpdate()->firstOrFail();
            $care = SejourSoin::query()->where('sejour_id', $fresh->id)->whereKey($careId)->lockForUpdate()->firstOrFail();
            if (! $care->fait || $care->cancelled_at !== null) {
                throw ValidationException::withMessages(['soin' => $care->cancelled_at !== null ? 'Cette prise est déjà annulée.' : 'Seule une prise donnée s’annule.']);
            }
            // Après la sortie, seule une prise notée après elle (non facturée) s'annule (sixième vérification)
            if (($fresh->sorti_le !== null || $fresh->facture_id !== null) && ! self::isUnbilled($fresh, $care)) {
                throw ValidationException::withMessages(['soin' => 'Facture déjà émise : la prise ne s’annule plus ici, corrigez la facture en caisse.']);
            }
            $sheet = StaySheets::sheetDayOf($care->jour->toDateString(), $care->prevu_a);
            $cancellation = [
                'cancelled_at' => now(),
                'cancelled_by_id' => $user?->id,
                'cancelled_by_name' => $this->sheets->authorName($user),
                'cancelled_late' => $sheet < $this->sheets->today($fresh),
            ];
            $care->update($cancellation);
            // L'historique de la prise : elle peut se noter de nouveau, l'annulation reste
            $care->cancellations()->create([
                ...$cancellation,
                'given_at' => $care->fait_le,
                'given_by_name' => $care->done_by_name,
                'dose_amount' => $care->dose_amount,
                'dose_unit' => $care->dose_unit,
                'late_noted_at' => $care->late_noted_at,
            ]);

            // SI ABV : la prise ponctuelle annulée n'a pas été utilisée, sa déclaration s'efface ou se désactive ;
            // celle d'un traitement déjà arrêté se recompte (sans prise restante, elle se retire)
            app(Declarations::class)->cancelFor($care);
            if ($care->treatment_id !== null && ($treatment = StayTreatment::query()->find($care->treatment_id)) !== null && Declarations::isTreatmentOver($treatment)) {
                app(Declarations::class)->treatmentStopped($treatment);
            }

            return ['care' => $care, 'stock' => $this->giveBackStock($care, $user)];
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return $result;
    }

    /**
     * Notée après la sortie : la facture, déjà émise, ne la compte pas. La
     * prise le sait depuis qu'elle a été notée, sous le verrou du séjour
     * (huitième vérification) : aucune comparaison d'heures à la seconde.
     */
    public static function isUnbilled(Sejour $sejour, SejourSoin $care): bool
    {
        return $care->fait && $care->is_unbilled && $sejour->sorti_le !== null;
    }

    /**
     * Ce que la prise avait sorti revient au stock ; le résultat dit ce qui est
     * vraiment revenu (rien sans produit, pour un stupéfiant, sans contenance).
     *
     * @return array<string, mixed>|null
     */
    private function giveBackStock(SejourSoin $care, ?User $user): ?array
    {
        $product = $care->produit;
        if ($product === null || $product->est_stupefiant) {
            return null;
        }
        $label = 'Prise annulée : '.$care->acte;
        // L'article, puis le stock, puis le journal : le même ordre qu'une prise, jamais
        // d'interblocage (neuvième et dixième vérifications)
        $product = $this->stock->lockProductAndStock($product, (int) Sejour::withoutGlobalScopes()->whereKey($care->sejour_id)->value('clinique_id'));
        // Les sorties de cette prise pas encore rendues (une prise notée de nouveau a les siennes)
        $moves = MouvementStock::withoutGlobalScopes()->where('source_type', $care->getMorphClass())->where('source_id', $care->id)->whereNull('reversed_at');
        if ($care->treatment_id !== null) {
            $takes = (clone $moves)->where('dose', '<', 0)->orderBy('id')->lockForUpdate()->get();
            // Contenance effacée depuis : la sortie est quand même traitée, la perte notée (huitième vérification)
            if ($takes->isEmpty()) {
                return null;
            }
            $back = ['dose' => 0.0, 'units' => 0, 'surplus' => 0.0, 'reason' => null];
            foreach ($takes as $take) {
                $one = $this->stock->giveBackTake($product, $take, $label, $user, $care, $care->dose_unit, $care->content_per_unit);
                $back = ['dose' => $back['dose'] + $one['dose'], 'units' => $back['units'] + $one['units'], 'surplus' => $back['surplus'] + $one['surplus'], 'reason' => $one['reason'] ?? $back['reason']];
            }
            $isLost = $back['dose'] <= 0;

            return [
                'returned' => ! $isLost, 'lost' => $isLost, 'dose' => round($back['dose'], 3), 'surplus' => round($back['surplus'], 3), 'units' => $back['units'],
                // « discarded » : flacon jeté ; « finished » : fini, un autre ouvert ; « unit » : unité changée ; « full » : au delà de la contenance
                'reason' => $back['reason'], 'dose_unit' => $care->dose_unit ?? $product->dose_unit, 'unit' => $product->unite,
            ];
        }
        $taken = (clone $moves)->where('quantite', '<', 0)->lockForUpdate()->get();
        $units = -(int) $taken->sum('quantite');
        if ($units <= 0) {
            return null;
        }
        // Rendu aux lots d'où le soin les avait pris
        $this->stock->entrer($product, $units, $label, $user, $care, reverses: $taken);
        $taken->each(fn (MouvementStock $m) => $m->forceFill(['reversed_at' => now()])->save());

        return ['returned' => true, 'lost' => false, 'units' => $units];
    }
}
