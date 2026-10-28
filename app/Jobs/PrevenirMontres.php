<?php

namespace App\Jobs;

use App\Models\AppareilNotification;
use App\Models\Employe;
use App\Services\Notifications\CanalApns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * La journée de la clinique a changé (28.10.2026) : les montres de son
 * équipe reçoivent d'Apple une notification invisible et relisent leur
 * journée. Le réveil part 15 secondes après le premier changement ; les
 * changements de ces 15 secondes s'y joignent (le verrou tient jusqu'à
 * l'envoi) : un planning réorganisé ne fait qu'un réveil, qui voit tout.
 */
class PrevenirMontres implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string SUJET_MONTRE = 'ch.veterinary.client.watchkitapp';

    /** La pause avant l'envoi, en secondes */
    public const int PAUSE = 15;

    /** Plus long que la pause : le verrou ne tombe qu'à l'envoi (ou après une minute, si la file est arrêtée) */
    public int $uniqueFor = 60;

    public function __construct(public int $cliniqueId) {}

    public function uniqueId(): string
    {
        return (string) $this->cliniqueId;
    }

    public function handle(CanalApns $apns): void
    {
        if (blank(config('services.apns.cle_base64'))) {
            return;
        }
        $equipe = Employe::withoutGlobalScopes()->whereHas('cliniques', fn ($q) => $q->whereKey($this->cliniqueId))
            ->where('acces_suspendu', false)->whereNotNull('user_id')->pluck('user_id');
        $appareils = AppareilNotification::query()->whereIn('user_id', $equipe)->where('plateforme', 'watchos')->get()->groupBy('environnement');
        foreach ($appareils as $environnement => $liste) {
            $morts = $apns->envoyer($liste->pluck('jeton')->values()->all(), (string) $environnement, self::SUJET_MONTRE, ['aps' => ['content-available' => 1], 'veterinary' => 'journee']);
            if ($morts !== []) {
                AppareilNotification::query()->whereIn('jeton', $morts)->delete();
            }
        }
    }
}
