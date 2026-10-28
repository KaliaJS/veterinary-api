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
 * journée. Une seule fois par clinique toutes les 15 secondes : une
 * rafale de changements (un planning réorganisé) ne fait qu'un réveil.
 */
class PrevenirMontres implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string SUJET_MONTRE = 'ch.veterinary.client.watchkitapp';

    public int $uniqueFor = 15;

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
