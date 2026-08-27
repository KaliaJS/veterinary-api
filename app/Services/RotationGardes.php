<?php

namespace App\Services;

use App\Models\Employe;
use App\Models\GardeWeekend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rotation des gardes de week-end (docs/data-model.md §1) — DÉRIVÉE :
 * numéro de semaine modulo l'effectif vétérinaire de la clinique, dans
 * l'ordre d'ancienneté (id). Seule une réattribution (gardes_weekend)
 * est une donnée ; elle prime sur la rotation. Les « nuits de la
 * semaine » reviennent au vétérinaire suivant.
 */
class RotationGardes
{
    /** @return list<array<string, mixed>> */
    public function prochainsWeekends(int $nombre = 6): array
    {
        $veterinaires = Employe::query()
            ->where('role_libelle', 'like', 'Vétérinaire%')
            ->orderBy('id')
            ->get()
            ->values();

        if ($veterinaires->isEmpty()) {
            return [];
        }

        $premierSamedi = CarbonImmutable::today()->next(CarbonImmutable::SATURDAY);

        if (CarbonImmutable::today()->isSaturday()) {
            $premierSamedi = CarbonImmutable::today();
        }

        $reattributions = GardeWeekend::query()
            ->whereDate('samedi_le', '>=', $premierSamedi)
            ->get()
            ->keyBy(fn (GardeWeekend $garde): string => $garde->samedi_le->toDateString());

        return Collection::times($nombre, function (int $rang) use ($veterinaires, $premierSamedi, $reattributions): array {
            $samedi = $premierSamedi->addWeeks($rang - 1);
            $reattribution = $reattributions->get($samedi->toDateString());

            $indexRotation = intdiv($samedi->getTimestamp(), 604800) % $veterinaires->count();
            $index = $reattribution !== null
                ? max($veterinaires->search(fn (Employe $veterinaire): bool => $veterinaire->id === $reattribution->employe_id), 0)
                : $indexRotation;

            $deGarde = $veterinaires[$index];
            $suivant = $veterinaires[($index + 1) % $veterinaires->count()];

            return [
                'samedi_le' => $samedi->toDateString(),
                'dimanche_le' => $samedi->addDay()->toDateString(),
                'employe_id' => $deGarde->id,
                'nom' => $deGarde->nom,
                'initiales' => $deGarde->initiales,
                'nuits_semaine' => $suivant->nom,
                'est_reattribuee' => $reattribution !== null,
            ];
        })->all();
    }
}
