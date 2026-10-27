<?php

namespace Tests\Feature\Planification;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Tâches planifiées prêtes pour Laravel Cloud (27.10.2026) : chacune ne
 * tourne que sur un serveur à la fois, et le verrou d'une tâche tuée en
 * pleine course (déploiement) tombe avant son passage suivant, jamais
 * 24 h plus tard (durée par défaut de `withoutOverlapping`).
 */
class TachesPlanifieesTest extends TestCase
{
    /** @return list<Event> */
    private function taches(): array
    {
        return array_values(array_filter(app(Schedule::class)->events(), fn (Event $tache): bool => ! str_contains((string) $tache->command, 'inspire')));
    }

    public function test_chaque_tache_ne_tourne_que_sur_un_serveur(): void
    {
        $this->assertNotEmpty($this->taches());
        foreach ($this->taches() as $tache) {
            $this->assertTrue($tache->onOneServer, (string) $tache->command);
        }
    }

    public function test_le_verrou_d_une_tache_tuee_tombe_avant_trois_heures(): void
    {
        foreach ($this->taches() as $tache) {
            $this->assertTrue($tache->withoutOverlapping, (string) $tache->command);
            $this->assertLessThanOrEqual(180, $tache->expiresAt, (string) $tache->command);
        }
    }

    /** Toutes les 10 minutes ou chaque heure : le verrou d'une tâche tuée tombe avant le passage suivant. */
    public function test_une_tache_frequente_libere_son_verrou_avant_le_passage_suivant(): void
    {
        $frequences = ['*/10 * * * *' => 10, '0 * * * *' => 60];
        foreach ($this->taches() as $tache) {
            if (isset($frequences[$tache->expression])) {
                $this->assertLessThan($frequences[$tache->expression], $tache->expiresAt, (string) $tache->command);
            }
        }
    }
}
