<?php

namespace App\Services\Planning;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;

/**
 * Les rendez-vous à venir qui tombent hors des horaires de leur clinique
 * (04.11.2026) : un jour fermé, avant l'ouverture, après la fermeture ou
 * dans la coupure de midi. La fiche de la clinique les signale quand ses
 * horaires changent ; rien ne part au propriétaire. Annulés et tampons
 * exclus ; heures locales, dans le fuseau de la clinique.
 */
class AppointmentsOutsideHours
{
    public function __construct(private CalculateurCreneaux $calculateur) {}

    /** @return list<array{id: int, starts_at: string, ends_at: string, patient_name: ?string, owner_name: ?string}> */
    public function for(HoraireOuverture $horaires): array
    {
        $fuseau = $horaires->clinique->fuseau_horaire ?? 'Europe/Zurich';
        $plagesDuJour = [];

        return RendezVous::withoutGlobalScopes()
            ->where('clinique_id', $horaires->clinique_id)
            ->where('starts_at', '>=', now())
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where('type', '!=', TypeRendezVous::Tampon)
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at', 'patient_name', 'owner_name'])
            ->filter(function (RendezVous $rendezVous) use ($horaires, $fuseau, &$plagesDuJour): bool {
                $debut = CarbonImmutable::instance($rendezVous->starts_at)->setTimezone($fuseau);
                $fin = CarbonImmutable::instance($rendezVous->ends_at)->setTimezone($fuseau);
                $date = $debut->toDateString();
                $plagesDuJour[$date] ??= $this->calculateur->bornesDuJour($horaires, $debut->startOfDay())['plages'];
                $a = $debut->hour * 60 + $debut->minute;
                $b = $fin->toDateString() === $date ? $fin->hour * 60 + $fin->minute : 1440;

                return ! collect($plagesDuJour[$date])->contains(fn (array $p): bool => $a >= $p[0] && $b <= $p[1]);
            })
            ->map(fn (RendezVous $rendezVous): array => [
                'id' => $rendezVous->id,
                'starts_at' => $rendezVous->starts_at->toIso8601String(),
                'ends_at' => $rendezVous->ends_at->toIso8601String(),
                'patient_name' => $rendezVous->patient_name,
                'owner_name' => $rendezVous->owner_name,
            ])
            ->values()
            ->all();
    }
}
