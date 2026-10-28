<?php

namespace App\Events;

use App\Models\Paiement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * L'état d'un paiement au terminal ou par TWINT a changé (28.10.2026) :
 * diffusé tout de suite (sans file) sur le canal de la clinique ; la caisse
 * et la boutique l'attendent au lieu de relire. Message court (Reverb sur
 * Laravel Cloud refuse plus de 10 Ko), sans la facture.
 */
class PaiementMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Paiement $paiement) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->paiement->clinique_id)];
    }

    public function broadcastAs(): string
    {
        return 'paiement.mis-a-jour';
    }

    /**
     * Court et sans données personnelles : l'état de la tentative seulement.
     * La facture payée se relit par l'API, derrière ses droits.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->paiement->loadMissing('terminal');

        return [
            'id' => $this->paiement->id,
            'facture_id' => $this->paiement->facture_id,
            'moyen' => $this->paiement->moyen->value,
            'statut' => $this->paiement->statut->value,
            'erreur' => $this->paiement->erreur,
            'url_twint' => $this->paiement->url_twint,
            'terminal' => $this->paiement->terminal ? ['id' => $this->paiement->terminal->id, 'libelle' => $this->paiement->terminal->libelle] : null,
            'reussi_le' => $this->paiement->reussi_le?->toIso8601String(),
        ];
    }
}
