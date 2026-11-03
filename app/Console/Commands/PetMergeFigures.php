<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres de la fusion des dossiers (02.11.2026, étape 0), en lecture
 * seule : combien d'animaux sont réclamés par un compte, combien de puces
 * se trouvent dans plusieurs organisations ou sur plusieurs dossiers, et
 * combien de dossiers réclamés seraient fusionnés. Rien n'est modifié.
 */
class PetMergeFigures extends Command
{
    protected $signature = 'pets:merge-figures';

    protected $description = 'Chiffres (lecture seule) de la fusion des dossiers : animaux réclamés, puces partagées, doublons';

    public function handle(): int
    {
        // La puce sans espaces : « 756 098… » et « 756098… » sont la même
        $puce = "REPLACE(puce, ' ', '')";
        $avecPuce = fn () => DB::table('patients')->whereNotNull('puce')->where('puce', '!=', '');

        $parPuce = $avecPuce()
            ->selectRaw("$puce as numero, COUNT(*) as dossiers, COUNT(DISTINCT organisation_id) as organisations")
            ->groupByRaw($puce)
            ->get();
        $enDouble = $parPuce->where('dossiers', '>', 1);

        $reclamesAFusionner = $avecPuce()
            ->whereNotNull('proprietaire_id')
            ->whereIn(DB::raw($puce), $avecPuce()->selectRaw($puce)->groupByRaw($puce)->havingRaw('COUNT(*) > 1'))
            ->count();

        $this->table(['Chiffre', 'Nombre'], [
            ['Animaux en tout', DB::table('patients')->count()],
            ['Animaux de clinique réclamés par un compte', DB::table('patients')->whereNotNull('proprietaire_id')->whereNotNull('organisation_id')->count()],
            ['Animaux créés par leur propriétaire (sans clinique)', DB::table('patients')->whereNull('organisation_id')->count()],
            ['Puces suivies par plusieurs organisations', $parPuce->where('organisations', '>', 1)->count()],
            ['Puces sur plusieurs dossiers', $enDouble->count()],
            ['Dossiers en trop (doublons de puce)', $enDouble->sum(fn ($r): int => $r->dossiers - 1)],
            ['Animaux réclamés dont la puce est sur un autre dossier (à fusionner)', $reclamesAFusionner],
        ]);
        $this->line('Lecture seule : rien n’a été modifié.');

        return self::SUCCESS;
    }
}
