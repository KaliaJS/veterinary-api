<?php

namespace App\Http\Resources\V1;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\EnregistrementConsultation;
use App\Models\PartieEnregistrement;
use App\Services\Transcription\ReceptionAudio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un enregistrement vu par le vétérinaire : où en sont ses parties (les
 * morceaux déjà reçus, pour reprendre un envoi coupé), la transcription
 * (voix, temps, texte) et, une fois rédigé, le brouillon SOAP à relire
 * avec ses doutes à vérifier.
 *
 * @mixin EnregistrementConsultation
 */
class EnregistrementConsultationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $parties = $this->parties;
        $reception = app(ReceptionAudio::class);

        return [
            'id' => $this->id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'statut' => $this->statut->value,
            'erreur' => $this->erreur,
            'parties_attendues' => $this->parties_attendues,
            'prochaine_partie' => (int) $parties->max('numero') + 1,
            'duree_secondes' => (int) $parties->sum('duree_secondes'),
            'parties' => $parties->map(fn (PartieEnregistrement $partie) => [
                'numero' => $partie->numero,
                'statut' => $partie->statut->value,
                'morceaux' => $partie->morceaux,
                'morceaux_recus' => $partie->statut === StatutPartie::Envoi ? $reception->recus($partie) : null,
                'duree_secondes' => $partie->duree_secondes,
            ])->values(),
            'transcription' => $parties
                ->flatMap(fn (PartieEnregistrement $partie) => collect($partie->segments ?? [])->map(fn (array $segment) => [
                    'partie' => $partie->numero,
                    ...$segment,
                ]))
                ->values(),
            'brouillon' => in_array($this->statut, [StatutEnregistrement::Pret, StatutEnregistrement::Valide], true) ? $this->brouillon : null,
            'cree_le' => $this->created_at,
            'redige_le' => $this->redige_le,
            'valide_le' => $this->valide_le,
        ];
    }
}
