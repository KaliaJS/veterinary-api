<?php

namespace App\Console\Commands;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Services\RotationGardes;
use Illuminate\Console\Command;
use Throwable;

/**
 * Le registre des gardes (Jim, 11.11.2026, É27 : « Every night,
 * automatically » ; OLT 1 art. 73) : chaque matin, la nuit passée de
 * chaque clinique s'enregistre telle que l'équipe l'a vue ; les quatre
 * semaines d'avant aussi tant qu'elles ne le sont pas (un passage manqué,
 * le premier passage). Regarder le Planning n'écrit rien.
 */
class EnregistrerGardes extends Command
{
    protected $signature = 'gardes:enregistrer';

    protected $description = 'Enregistre les nuits de garde passées telles que l’équipe les a vues (registre)';

    public function handle(ContexteClinique $contexte): int
    {
        $etat = $contexte->etat();
        $ecrites = 0;
        $echecs = 0;
        try {
            Clinique::query()->orderBy('id')->each(function (Clinique $clinique) use ($contexte, &$ecrites, &$echecs): void {
                // Une clinique en erreur n'arrête pas les autres
                try {
                    $contexte->definir($clinique);
                    $hier = aujourdhui($clinique)->toImmutable()->subDay();
                    // Les quatre semaines : le premier passage complète l'histoire des suggestions, un passage manqué se rattrape
                    $ecrites += app(RotationGardes::class)->enregistrer($hier->subDays(26), $hier);
                } catch (Throwable $e) {
                    report($e);
                    $echecs++;
                }
            });
        } finally {
            $contexte->retablir($etat);
        }

        $this->info($ecrites.' nuit(s) enregistrée(s).');
        if ($echecs > 0) {
            $this->error($echecs.' clinique(s) en erreur, rattrapées au prochain passage.');
        }

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
