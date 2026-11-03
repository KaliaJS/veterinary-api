<?php

namespace App\Console\Commands;

use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\Sejour;
use App\Services\Hospitalisation\StaySheets;
use Database\Seeders\HospitalisationDemo;
use Illuminate\Console\Command;

/**
 * Remet la ronde de démo à aujourd'hui (03.11.2026), en local seulement :
 * les séjours en cours de l'organisation de démo gardent leur animal et
 * leur box ; leur entrée, leurs traitements et leurs feuilles sont réécrits
 * autour de maintenant (des animaux là depuis 1, 2, 3 et 4 jours).
 */
class RefreshHospitalisationDemo extends Command
{
    protected $signature = 'hospitalisation:demo';

    protected $description = 'Réécrit la ronde de démo de l’hospitalisation autour d’aujourd’hui (local seulement)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Seulement en local : cette commande réécrit les séjours de démo.');

            return self::FAILURE;
        }

        $organisation = Organisation::query()->where('nom', 'Deux Rives Vétérinaires')->first();
        if (! $organisation) {
            $this->error('Organisation de démo introuvable.');

            return self::FAILURE;
        }
        $cliniques = Clinique::withoutGlobalScopes()->whereRelation('societe', 'organisation_id', $organisation->id)->pluck('id');
        $sejours = Sejour::withoutGlobalScopes()->whereIn('clinique_id', $cliniques)->enCours()->with('clinique')->orderBy('clinique_id')->orderBy('box')->get();

        $demo = app(HospitalisationDemo::class);
        foreach ($sejours as $sejour) {
            $demo->fill($sejour);
            $sejour->refresh();
            $this->line(sprintf('Box %02d · %s : %s', $sejour->box, $sejour->motif, app(StaySheets::class)->sinceLabel($sejour)));
        }

        return self::SUCCESS;
    }
}
