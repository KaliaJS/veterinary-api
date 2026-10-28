<?php

namespace App\Jobs;

use App\Enums\RoleLignePlanning;
use App\Models\AppareilNotification;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Services\Notifications\CanalApns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;

/**
 * Une colonne du planning a changé (28.10.2026) : les montres qui la
 * montrent reçoivent d'Apple une notification invisible et relisent leur
 * journée. Choix B de Jim : seules les montres concernées, celle du
 * praticien de la colonne et, pour la première colonne de consultation,
 * celles de l'équipe sans colonne à soi (même choix que la montre,
 * Chargement.swift). Le réveil part 15 secondes après le premier
 * changement de la colonne ; ceux de ces 15 secondes s'y joignent (le
 * verrou tient jusqu'à l'envoi) ; au-delà, un réveil toutes les 15
 * secondes au plus.
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

    /** La colonne ; 0 pour une tâche mise en file avant le 28.10.2026 (sans colonne) : personne n'est réveillé */
    public int $ligneId = 0;

    public function __construct(public int $cliniqueId, int $ligneId)
    {
        $this->ligneId = $ligneId;
    }

    public function uniqueId(): string
    {
        return $this->cliniqueId.':'.$this->ligneId;
    }

    public function handle(CanalApns $apns): void
    {
        if (blank(config('services.apns.cle_base64'))) {
            return;
        }
        $appareils = AppareilNotification::query()->whereIn('user_id', $this->concernes())->where('plateforme', 'watchos')->get()->groupBy('environnement');
        foreach ($appareils as $environnement => $liste) {
            $morts = $apns->envoyer($liste->pluck('jeton')->values()->all(), (string) $environnement, self::SUJET_MONTRE, ['aps' => ['content-available' => 1], 'veterinary' => 'journee']);
            if ($morts !== []) {
                AppareilNotification::query()->whereIn('jeton', $morts)->delete();
            }
        }
    }

    /**
     * Les comptes dont la montre montre cette colonne : son praticien ; et,
     * si c'est la première colonne de consultation, l'équipe sans colonne
     * à soi. Jamais un employé suspendu ni hors de la clinique.
     *
     * @return Collection<int, int>
     */
    private function concernes(): Collection
    {
        $lignes = fn () => LignePlanning::withoutGlobalScopes()->where('clinique_id', $this->cliniqueId)->enService();
        $ligne = $lignes()->find($this->ligneId);
        if ($ligne === null) {
            return collect();
        }
        $equipe = Employe::withoutGlobalScopes()->whereHas('cliniques', fn ($q) => $q->whereKey($this->cliniqueId))
            ->where('acces_suspendu', false)->whereNotNull('user_id');

        $premiere = $lignes()->where('role', RoleLignePlanning::Consultation)->orderBy('ordre')->orderBy('id')->value('id');
        $sansColonne = $premiere === $ligne->id
            ? (clone $equipe)->whereNotIn('id', $lignes()->whereNotNull('employe_id')->select('employe_id'))->pluck('user_id')
            : collect();
        $praticien = $ligne->employe_id !== null ? (clone $equipe)->whereKey($ligne->employe_id)->pluck('user_id') : collect();

        return $praticien->merge($sansColonne)->unique()->values();
    }
}
