<?php

namespace App\Services\Facturation;

use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use Illuminate\Database\Eloquent\Collection;

/**
 * Arrêté de caisse (Z) : un seul calcul, pour l'aperçu à l'écran et pour
 * l'enregistrement (26.10.2026). Totaux par moyen de ce qui est passé à la
 * caisse (sans l'acompte payé en ligne), fond (celui que la clôture
 * précédente a laissé ; le premier jour, celui saisi), espèces attendues,
 * billets comptés, écart et dépôt. L'écran n'additionne rien.
 */
class ArreteDeCaisse
{
    /** Billets et pièces du tiroir, en centimes */
    public const COUPURES = [20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50];

    /** Fond proposé le premier jour, tant qu'aucun n'est saisi */
    public const FOND_PREMIER_JOUR = 200.0;

    /**
     * Factures payées de la clinique qu'aucune clôture ne couvre encore.
     *
     * @return Collection<int, Facture>
     */
    public function factures(Clinique $clinique, bool $verrouiller = false): Collection
    {
        $requete = Facture::query()
            ->where('statut', StatutFacture::Payee)
            ->whereNull('cloture_caisse_id')
            ->where('clinique_id', $clinique->id)
            ->with(['lignes', 'encaisseePar'])
            // Une carte ou un TWINT payé au terminal : une seule requête pour toutes les factures
            ->withExists(['paiements as preuve_terminal' => fn ($paiements) => $paiements->whereIn('statut', Facture::preuvesDeTerminal())]);

        return ($verrouiller ? $requete->lockForUpdate() : $requete)->get();
    }

    /**
     * @param  Collection<int, Facture>  $factures
     * @param  array<int|string, int|string>|null  $comptage  nombre de billets ou pièces par coupure (centimes)
     * @return array{encaissements: int, moyens: array<string, array{montant: float, nombre: int, sans_terminal: int, saisies_par: list<string>}>, total: float, fond: float, fond_impose: bool, especes_theoriques: float, coupures: list<array{centimes: int, nombre: int, montant: float}>, especes_comptees: float, ecart: float, nouveau_fond: float, depot: float, depot_suggere: float, sans_terminal: int, mode_integre: bool, motif_justification: ?string, justification_requise: bool}
     */
    public function calculer(Clinique $clinique, Collection $factures, ?float $fondSaisi, ?array $comptage, ?float $especesComptees, ?float $nouveauFond, ?float $depot): array
    {
        $modeIntegre = $clinique->encaisseAvecVeterinary();

        $moyens = [];
        foreach (MoyenPaiement::cases() as $moyen) {
            $liste = $factures->filter(fn (Facture $facture): bool => $facture->moyen_paiement === $moyen);
            // Une carte ou un TWINT sans preuve du terminal : saisi à la main
            $sansTerminal = $moyen === MoyenPaiement::Especes ? collect() : $liste->filter(
                fn (Facture $facture): bool => ! $facture->getAttribute('preuve_terminal')
            );
            $moyens[$moyen->value] = [
                // Ce qui est passé à la caisse : l'acompte en ligne n'y est pas
                'montant' => round((float) $liste->sum(fn (Facture $facture): float => $facture->resteAPayer()), 2),
                'nombre' => $liste->count(),
                'sans_terminal' => $sansTerminal->count(),
                'saisies_par' => $sansTerminal->map(fn (Facture $facture): ?string => $facture->encaisseePar?->name)->filter()->unique()->values()->all(),
            ];
        }
        $especes = $moyens[MoyenPaiement::Especes->value]['montant'];

        $precedente = ClotureCaisse::query()->where('clinique_id', $clinique->id)->orderByDesc('arretee_le')->orderByDesc('id')->first();
        $fond = $precedente !== null ? round((float) $precedente->nouveau_fond, 2) : round($fondSaisi ?? self::FOND_PREMIER_JOUR, 2);

        $coupures = [];
        $centimesComptes = 0;
        foreach (self::COUPURES as $centimes) {
            $nombre = (int) ($comptage[$centimes] ?? $comptage[(string) $centimes] ?? 0);
            $centimesComptes += $nombre * $centimes;
            $coupures[] = ['centimes' => $centimes, 'nombre' => $nombre, 'montant' => round($nombre * $centimes / 100, 2)];
        }
        $comptees = $comptage !== null ? round($centimesComptes / 100, 2) : round((float) $especesComptees, 2);

        $theoriques = round($fond + $especes, 2);
        $ecart = round($comptees - $theoriques, 2);
        $nouveauFond = round($nouveauFond ?? $fond, 2);
        $suggere = max(0.0, round($comptees - $nouveauFond, 2));
        $sansTerminalTotal = array_sum(array_column($moyens, 'sans_terminal'));

        return [
            'encaissements' => $factures->count(),
            'moyens' => $moyens,
            'total' => round(array_sum(array_column($moyens, 'montant')), 2),
            'fond' => $fond,
            'fond_impose' => $precedente !== null,
            'especes_theoriques' => $theoriques,
            'coupures' => $coupures,
            'especes_comptees' => $comptees,
            'ecart' => $ecart,
            'nouveau_fond' => $nouveauFond,
            'depot' => $depot !== null ? round($depot, 2) : $suggere,
            'depot_suggere' => $suggere,
            'sans_terminal' => $sansTerminalTotal,
            'mode_integre' => $modeIntegre,
            // Un écart, ou (paiements intégrés) une carte ou un TWINT saisi à la main
            'motif_justification' => abs($ecart) >= 0.005 ? 'ecart' : ($modeIntegre && $sansTerminalTotal > 0 ? 'sans_terminal' : null),
            'justification_requise' => abs($ecart) >= 0.005 || ($modeIntegre && $sansTerminalTotal > 0),
        ];
    }
}
