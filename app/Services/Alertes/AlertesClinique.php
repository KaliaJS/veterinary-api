<?php

namespace App\Services\Alertes;

use App\Enums\StatutPaiement;
use App\Enums\TypeAlerte;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\Lot;
use App\Models\Organisation;
use App\Models\Paiement;
use App\Models\Produit;
use App\Services\Diagnostic\ConsommationDiagnostic;
use App\Services\Transcription\ConsommationTranscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Alertes de la clinique (23.10.2026), calculées à la demande, jamais
 * stockées : ce qui est vrai maintenant. Stock (domaine stocks) : produits
 * sous leur seuil (stock de toutes les cliniques de l'organisation), lots
 * de la clinique périmés ou qui périment d'ici la fin du mois suivant.
 * Consommation (domaine organisation) : transcription et suggestions
 * diagnostiques à 80 % puis à 100 % de leur plafond. Paiement (domaine
 * facturation) : paiements contestés, reçus en trop que Stripe a refusé
 * de rendre, et remboursés chez Stripe depuis moins de 30 jours (la
 * facture, elle, reste payée). Chaque alerte a une
 * clé qui change avec son contenu : une alerte vue redevient nouvelle
 * quand la situation change.
 *
 * @phpstan-type Alerte array{cle: string, type: string, niveau: string, titre: string, detail: string, cible: string}
 */
class AlertesClinique
{
    public function __construct(
        private ConsommationTranscription $transcription,
        private ConsommationDiagnostic $diagnostic,
    ) {}

    /** @return list<Alerte> */
    public function pour(Clinique $clinique, bool $stock, bool $consommation, bool $paiement = false): array
    {
        return [
            ...($paiement ? $this->paiements($clinique) : []),
            ...($stock ? $this->stock($clinique) : []),
            ...($consommation && $clinique->societe?->organisation ? $this->consommation($clinique->societe->organisation) : []),
        ];
    }

    /** @return list<Alerte> */
    public function paiements(Clinique $clinique): array
    {
        $alertes = [];
        $paiements = fn (StatutPaiement $statut) => Paiement::withoutGlobalScopes()
            ->with(['facture' => fn ($requete) => $requete->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)
            ->where('statut', $statut)
            ->when($statut === StatutPaiement::Rembourse, fn ($requete) => $requete->where('updated_at', '>=', now()->subDays(30)))
            ->orderByDesc('updated_at')
            ->get();

        $litiges = $paiements(StatutPaiement::Litige);
        if ($litiges->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'litiges:'.$this->empreinte($litiges->map(fn (Paiement $p) => (string) $p->id)),
                'urgent',
                $this->compte($litiges->count(), 'paiement contesté', 'paiements contestés'),
                $this->noms($litiges->map(fn (Paiement $p) => $this->paiement($p))).'. Répondez depuis Réglages, Paiements, avant l’échéance de la banque.',
                'reglages:paiements',
            );
        }

        // Reçu après coup (facture déjà payée, montant différent) et que
        // Stripe a refusé de rendre : le client a payé deux fois
        $aRendre = Paiement::withoutGlobalScopes()
            ->with(['facture' => fn ($requete) => $requete->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)
            ->where('statut', StatutPaiement::Reussi)
            ->where('erreur', 'like', 'À rembourser%')
            ->orderByDesc('updated_at')
            ->get();
        if ($aRendre->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'a-rendre:'.$this->empreinte($aRendre->map(fn (Paiement $p) => (string) $p->id)),
                'urgent',
                $this->compte($aRendre->count(), 'paiement à rembourser', 'paiements à rembourser'),
                $this->noms($aRendre->map(fn (Paiement $p) => $this->paiement($p))).'. Reçu en trop, et le remboursement automatique a échoué : remboursez-le depuis Réglages, Paiements.',
                'reglages:paiements',
            );
        }

        // Acomptes payés en ligne et contestés à la banque (24.10.2026)
        $acomptes = DemandeRendezVous::withoutGlobalScopes()
            ->where('clinique_id', $clinique->id)
            ->where('acompte_litige', 'ouvert')
            ->orderByDesc('updated_at')
            ->get();
        if ($acomptes->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'acomptes-contestes:'.$this->empreinte($acomptes->map(fn (DemandeRendezVous $d) => (string) $d->id)),
                'urgent',
                $this->compte($acomptes->count(), 'acompte contesté', 'acomptes contestés'),
                $this->noms($acomptes->map(fn (DemandeRendezVous $d) => 'acompte de '.$d->patient_name.' ('.$this->chf((int) $d->acompte_centimes).')')).'. Répondez depuis Réglages, Paiements, avant l’échéance de la banque.',
                'reglages:paiements',
            );
        }

        // Acompte rendu ou perdu au litige après la facture (24.10.2026) : la
        // facture le déduit encore, le client doit la différence
        $factures = Facture::withoutGlobalScopes()
            ->with('rendezVous.demandeEnLigne')
            ->where('clinique_id', $clinique->id)
            ->where('acompte', '>', 0)
            ->whereHas('rendezVous.demandeEnLigne', fn ($requete) => $requete->where('acompte_rembourse_le', '>=', now()->subDays(30)))
            ->orderByDesc('emise_le')
            ->get()
            ->filter(fn (Facture $f): bool => (int) round(($f->rendezVous?->demandeEnLigne?->acompteRetenu() ?? 0) * 100) < (int) round((float) $f->acompte * 100))
            ->values();
        if ($factures->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'acomptes-rendus:'.$this->empreinte($factures->map(fn (Facture $f) => $f->id.'-'.$f->rendezVous?->demandeEnLigne?->acompte_rembourse_centimes)),
                'warn',
                $this->compte($factures->count(), 'acompte rendu après la facture', 'acomptes rendus après la facture'),
                $this->noms($factures->map(fn (Facture $f) => 'facture '.$f->numero.' ('.$this->chf((int) round((float) $f->acompte * 100)).' déduits, '
                    .$this->chf((int) round(($f->rendezVous?->demandeEnLigne?->acompteRetenu() ?? 0) * 100)).' encore encaissés)')).'. La facture déduit plus que ce qui reste : corrigez-la pour que le client paie la différence.',
                'facturation',
            );
        }

        // Acompte rendu en partie chez Stripe, sans facture encore (24.10.2026) :
        // la clinique le sait ; la facture déduira ce qui reste
        $partiels = DemandeRendezVous::withoutGlobalScopes()
            ->where('clinique_id', $clinique->id)
            ->where('acompte_rembourse_le', '>=', now()->subDays(30))
            ->where('acompte_rembourse_centimes', '>', 0)
            ->whereColumn('acompte_rembourse_centimes', '<', 'acompte_centimes')
            ->whereDoesntHave('rendezVous.facture')
            ->orderByDesc('acompte_rembourse_le')
            ->get();
        if ($partiels->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'acomptes-partiels:'.$this->empreinte($partiels->map(fn (DemandeRendezVous $d) => $d->id.'-'.$d->acompte_rembourse_centimes)),
                'warn',
                $this->compte($partiels->count(), 'acompte rendu en partie', 'acomptes rendus en partie'),
                $this->noms($partiels->map(fn (DemandeRendezVous $d) => 'acompte de '.$d->patient_name.' ('.$this->chf((int) $d->acompte_rembourse_centimes).' rendus sur '.$this->chf((int) $d->acompte_centimes).')')).'. Rendu chez Stripe : la facture du rendez-vous déduira ce qui reste.',
                'facturation',
            );
        }

        // Rendus en entier, ou en partie (le paiement reste réussi)
        $rembourses = $paiements(StatutPaiement::Rembourse)->concat(Paiement::withoutGlobalScopes()
            ->with(['facture' => fn ($requete) => $requete->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)
            ->where('statut', StatutPaiement::Reussi)
            ->where('erreur', 'like', 'Remboursé en partie%')
            ->where('updated_at', '>=', now()->subDays(30))
            ->get());
        if ($rembourses->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Paiement,
                'rembourses:'.$this->empreinte($rembourses->map(fn (Paiement $p) => (string) $p->id)),
                'warn',
                $this->compte($rembourses->count(), 'paiement remboursé', 'paiements remboursés'),
                $this->noms($rembourses->map(fn (Paiement $p) => $this->paiement($p))).'. La facture reste payée : corrigez-la si besoin.',
                'facturation',
            );
        }

        return $alertes;
    }

    /** @return list<Alerte> */
    public function stock(Clinique $clinique): array
    {
        $alertes = [];
        $organisationId = $clinique->societe?->organisation_id;

        $sousSeuil = Produit::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('seuil_alerte', '>', 0)
            ->withSum(['stocks as stock_total' => fn ($requete) => $requete->withoutGlobalScopes()], 'quantite')
            ->orderBy('nom')
            ->get()
            ->filter(fn (Produit $produit) => (int) $produit->stock_total <= $produit->seuil_alerte)
            ->values();
        if ($sousSeuil->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Stock,
                'seuil:'.$this->empreinte($sousSeuil->map(fn (Produit $p) => $p->id.'-'.(int) $p->stock_total)),
                $sousSeuil->contains(fn (Produit $p) => (int) $p->stock_total === 0) ? 'urgent' : 'warn',
                $this->compte($sousSeuil->count(), 'produit sous le seuil', 'produits sous le seuil'),
                $this->noms($sousSeuil->map(fn (Produit $p) => $p->nom.' ('.(int) $p->stock_total.')')),
            );
        }

        $mois = CarbonImmutable::now('Europe/Zurich')->startOfMonth();
        $lots = Lot::withoutGlobalScopes()
            ->with(['produit' => fn ($requete) => $requete->withoutGlobalScopes()])
            ->where('clinique_id', $clinique->id)
            ->where('quantite', '>', 0)
            ->where('peremption_le', '<', $mois->addMonths(2)->toDateString())
            ->orderBy('peremption_le')
            ->get();
        [$perimes, $bientot] = $lots->partition(fn (Lot $lot) => $lot->peremption_le->lt($mois));

        if ($perimes->isNotEmpty()) {
            $alertes[] = $this->alerte(
                TypeAlerte::Stock,
                'perimes:'.$this->empreinte($perimes->map(fn (Lot $l) => $l->id.'-'.$l->quantite)),
                'urgent',
                $this->compte($perimes->count(), 'lot périmé à retirer', 'lots périmés à retirer'),
                $this->noms($perimes->map(fn (Lot $l) => $this->lot($l))),
            );
        }
        if ($bientot->isNotEmpty()) {
            $fin = $mois->addMonth()->locale('fr')->isoFormat('MMMM');
            $alertes[] = $this->alerte(
                TypeAlerte::Stock,
                'peremption:'.$this->empreinte($bientot->map(fn (Lot $l) => $l->id.'-'.$l->quantite)),
                'warn',
                $this->compte($bientot->count(), 'lot périme', 'lots périment').' d’ici fin '.$fin,
                $this->noms($bientot->map(fn (Lot $l) => $this->lot($l))),
            );
        }

        return $alertes;
    }

    /** @return list<Alerte> */
    public function consommation(Organisation $organisation): array
    {
        $alertes = [];
        [$debut] = $this->transcription->periode($organisation);
        $periode = $debut->toDateString();

        $plafond = $organisation->plafond_transcription;
        $montant = $this->transcription->montant($this->transcription->secondesDeLaPeriode($organisation));
        if ($plafond > 0 && $montant * 100 >= $plafond * 80) {
            $atteint = $montant >= $plafond;
            $alertes[] = $this->alerte(
                TypeAlerte::Consommation,
                'transcription:'.$periode.':'.($atteint ? 100 : 80),
                $atteint ? 'urgent' : 'warn',
                $atteint ? 'Transcription : plafond atteint' : 'Transcription : 80 % du plafond',
                $this->chf($montant).' sur '.$this->chf($plafond).' pour cette période.',
                'reglages:transcription',
            );
        }

        $plafond = $organisation->plafond_diagnostic;
        $montant = $this->diagnostic->dela($organisation)['montant'];
        if ($plafond > 0 && $montant * 100 >= $plafond * 80) {
            $atteint = $montant + $this->diagnostic->tarif() > $plafond;
            $alertes[] = $this->alerte(
                TypeAlerte::Consommation,
                'diagnostic:'.$periode.':'.($atteint ? 100 : 80),
                $atteint ? 'urgent' : 'warn',
                $atteint ? 'Diagnostic IA : plafond atteint' : 'Diagnostic IA : 80 % du plafond',
                $this->chf($montant).' sur '.$this->chf($plafond).' pour cette période.',
                'reglages:diagnostic',
            );
        }

        return $alertes;
    }

    /** @return Alerte */
    private function alerte(TypeAlerte $type, string $cle, string $niveau, string $titre, string $detail, string $cible = 'stocks'): array
    {
        return ['cle' => $type->value.':'.$cle, 'type' => $type->value, 'niveau' => $niveau, 'titre' => $titre, 'detail' => $detail, 'cible' => $cible];
    }

    /** @param  Collection<int, covariant string>  $parties */
    private function empreinte(Collection $parties): string
    {
        return substr(md5($parties->implode('|')), 0, 12);
    }

    /**
     * Les trois premiers, puis « et N autres ».
     *
     * @param  Collection<int, covariant string>  $noms
     */
    private function noms(Collection $noms): string
    {
        $reste = $noms->count() - 3;

        return $noms->take(3)->implode(', ').($reste > 0 ? ' et '.$reste.' '.($reste > 1 ? 'autres' : 'autre') : '');
    }

    private function compte(int $n, string $un, string $plusieurs): string
    {
        return $n.' '.($n > 1 ? $plusieurs : $un);
    }

    private function lot(Lot $lot): string
    {
        // Le numéro porte souvent déjà « LOT » : pas de « lot LOT … »
        $numero = preg_match('/^lot\b/i', $lot->numero_lot) ? $lot->numero_lot : 'lot '.$lot->numero_lot;

        return ($lot->produit->nom ?? 'Produit').', '.$numero.' ('.$lot->peremption_le->format('m.Y').')';
    }

    private function paiement(Paiement $paiement): string
    {
        return 'facture '.($paiement->facture->numero ?? '?').' ('.$this->chf((int) round((float) $paiement->montant * 100)).')';
    }

    private function chf(int $centimes): string
    {
        return chf($centimes / 100);
    }
}
