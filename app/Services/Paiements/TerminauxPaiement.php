<?php

namespace App\Services\Paiements;

use App\Models\Clinique;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Illuminate\Validation\ValidationException;

/**
 * Terminaux de paiement d'une clinique, sur le compte de paiement de sa
 * société : appairage par le code affiché sur le lecteur (la clinique
 * reçoit sa « location » au premier appairage), état en ligne, retrait.
 */
class TerminauxPaiement
{
    public function __construct(private ComptesPaiement $comptes) {}

    public function appairer(Clinique $clinique, string $codeAppairage, string $libelle): TerminalPaiement
    {
        $passerelle = $this->comptes->passerellePour($clinique, pourEncaisser: true);
        $lecteur = $passerelle->appairerLecteur($codeAppairage, $libelle, $this->location($clinique, $passerelle));

        return TerminalPaiement::create([
            'clinique_id' => $clinique->id,
            'stripe_reader_id' => $lecteur['id'],
            'libelle' => $lecteur['libelle'] ?: $libelle,
            'modele' => $lecteur['modele'],
            'numero_serie' => $lecteur['numero_serie'],
        ]);
    }

    /** En ligne ? (null : état inconnu, prestataire injoignable ou compte absent) */
    public function estEnLigne(TerminalPaiement $terminal): ?bool
    {
        try {
            return $this->comptes->passerellePour($terminal->clinique_id)->lireLecteur($terminal->stripe_reader_id)['en_ligne'];
        } catch (ErreurPaiement|ValidationException) {
            return null;
        }
    }

    public function retirer(TerminalPaiement $terminal): void
    {
        try {
            $this->comptes->passerellePour($terminal->clinique_id)->supprimerLecteur($terminal->stripe_reader_id);
        } catch (ValidationException) {
            // Plus de compte de paiement : le terminal n'existe plus que chez nous
        } catch (ErreurPaiement $e) {
            // Déjà supprimé chez Stripe : on retire quand même localement
            if ($e->codeStripe !== 'resource_missing') {
                throw $e;
            }
        }

        $terminal->delete();
    }

    private function location(Clinique $clinique, PasserellePaiement $passerelle): string
    {
        if ($clinique->stripe_location_id) {
            return $clinique->stripe_location_id;
        }

        $id = $passerelle->creerLocation(
            $clinique->nom,
            ['rue' => $clinique->rue, 'npa' => $clinique->npa, 'ville' => $clinique->ville],
            ['clinique_id' => (string) $clinique->id],
        );

        $clinique->update(['stripe_location_id' => $id]);

        return $id;
    }
}
