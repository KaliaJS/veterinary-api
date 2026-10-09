<?php

namespace App\Services\Paiements;

use App\Models\Clinique;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;

/**
 * Terminaux de paiement d'une clinique : appairage par le code affiché
 * sur le lecteur (la clinique reçoit sa « location » Stripe au premier
 * appairage), état en ligne, retrait.
 */
class TerminauxPaiement
{
    public function __construct(private PasserellePaiement $passerelle) {}

    public function appairer(Clinique $clinique, string $codeAppairage, string $libelle): TerminalPaiement
    {
        $lecteur = $this->passerelle->appairerLecteur($codeAppairage, $libelle, $this->location($clinique));

        return TerminalPaiement::create([
            'clinique_id' => $clinique->id,
            'stripe_reader_id' => $lecteur['id'],
            'libelle' => $lecteur['libelle'] ?: $libelle,
            'modele' => $lecteur['modele'],
            'numero_serie' => $lecteur['numero_serie'],
        ]);
    }

    /** En ligne chez Stripe ? (null : état inconnu, Stripe injoignable) */
    public function estEnLigne(TerminalPaiement $terminal): ?bool
    {
        try {
            return $this->passerelle->lireLecteur($terminal->stripe_reader_id)['en_ligne'];
        } catch (ErreurPaiement) {
            return null;
        }
    }

    public function retirer(TerminalPaiement $terminal): void
    {
        try {
            $this->passerelle->supprimerLecteur($terminal->stripe_reader_id);
        } catch (ErreurPaiement $e) {
            // Déjà supprimé chez Stripe : on retire quand même localement
            if ($e->codeStripe !== 'resource_missing') {
                throw $e;
            }
        }

        $terminal->delete();
    }

    private function location(Clinique $clinique): string
    {
        if ($clinique->stripe_location_id) {
            return $clinique->stripe_location_id;
        }

        $id = $this->passerelle->creerLocation(
            $clinique->nom,
            ['rue' => $clinique->rue, 'npa' => $clinique->npa, 'ville' => $clinique->ville],
            ['clinique_id' => (string) $clinique->id],
        );

        $clinique->update(['stripe_location_id' => $id]);

        return $id;
    }
}
