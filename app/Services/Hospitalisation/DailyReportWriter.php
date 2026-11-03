<?php

namespace App\Services\Hospitalisation;

/**
 * Rédacteur du rapport de la journée d'un animal hospitalisé (03.11.2026),
 * remplaçable (Claude aujourd'hui). Il ne reprend que la feuille qu'on lui
 * donne : rien d'inventé. Le rapport attend toujours la relecture et la
 * signature d'un membre de l'équipe.
 */
interface DailyReportWriter
{
    /**
     * @param  string  $facts  la feuille du jour, ligne par ligne (servie, jamais devinée)
     *
     * @throws DailyReportImpossible refus définitif
     */
    public function write(string $facts): string;
}
