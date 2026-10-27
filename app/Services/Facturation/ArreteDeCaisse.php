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
    // Billets et pièces suisses en centimes, du billet de 1000 à la pièce de 5 centimes (27.10.2026)
    public const COUPURES = [100000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];

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
     * @return array{encaissements: int, moyens: array<string, array{montant: float, nombre: int, sans_terminal: int, saisies_par: list<string>}>, total: float, fond: float, fond_impose: bool, especes_theoriques: float, coupures: list<array{centimes: int, nombre: int, montant: float}>, especes_comptees: float, ecart: float, nouveau_fond: float, depot: float, depot_suggere: float, sans_terminal: int, mode_integre: bool, motif_justification: ?string, motifs_justification: list<string>, justification_requise: bool, alerte_depot: ?string, non_ventile: float, apport_coffre: float}
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
        $depot = $depot !== null ? round($depot, 2) : $suggere;
        $sansTerminalTotal = array_sum(array_column($moyens, 'sans_terminal'));
        // Ce qui est compté part en dépôt ou reste en fond. On ne dépose
        // jamais plus que compté ; un fond refait avec l'argent du coffre est
        // gardé au Z (apport) ; ce qui n'est ni déposé ni en fond se justifie
        $alerte = $depot > $comptees + 0.005 ? 'Le dépôt ne dépasse jamais les espèces comptées.' : null;
        $nonVentile = max(0.0, round($comptees - $depot - $nouveauFond, 2));
        // Un dépôt plus grand que compté n'a pas d'apport : il est refusé tel quel
        $apportCoffre = $alerte === null ? max(0.0, round($depot + $nouveauFond - $comptees, 2)) : 0.0;
        // Une seule règle, pour l'aperçu et pour le Z : un écart, (paiements
        // intégrés) une carte ou un TWINT saisi à la main, des espèces ni
        // déposées ni dans le fond, ou de l'argent venu du coffre ; tous dits
        // quand plusieurs coexistent
        $motifs = array_keys(array_filter([
            'ecart' => abs($ecart) >= 0.005,
            'sans_terminal' => $modeIntegre && $sansTerminalTotal > 0,
            'non_ventile' => $nonVentile >= 0.005,
            // Le fond refait ou complété avec l'argent du coffre, avec ou sans
            // dépôt : accepté avec une note (décisions de Jim, 27.10.2026)
            'apport_coffre' => $apportCoffre >= 0.005,
        ]));

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
            'depot' => $depot,
            'depot_suggere' => $suggere,
            'sans_terminal' => $sansTerminalTotal,
            'mode_integre' => $modeIntegre,
            // Le premier motif, pour les versions de l'app qui n'en lisent qu'un
            'motif_justification' => $motifs[0] ?? null,
            'motifs_justification' => $motifs,
            'justification_requise' => $motifs !== [],
            'alerte_depot' => $alerte,
            'non_ventile' => $nonVentile,
            'apport_coffre' => $apportCoffre,
        ];
    }
}
