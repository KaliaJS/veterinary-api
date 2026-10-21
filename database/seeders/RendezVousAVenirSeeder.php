<?php

namespace Database\Seeders;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Rendez-vous des jours qui viennent (deux semaines), pour que le
 * calendrier de la prise de rendez-vous montre des heures déjà prises :
 * chaque jour ouvert, les mêmes plages occupent toutes les lignes de
 * Deux Rives et du Lac (une heure n'est prise que si plus aucune ligne
 * n'est libre). Rejouable : rien n'est ajouté s'ils existent déjà.
 */
class RendezVousAVenirSeeder extends Seeder
{
    use WithoutModelEvents;

    private const MOTIF = 'Consultation (démonstration)';

    /** Plages occupées, en minutes locales [début, durée], selon le jour */
    private const PLAGES = [
        [[540, 60], [920, 40]],
        [[620, 80]],
        [[480, 60], [1000, 40]],
        [[840, 60], [1080, 40]],
        [[560, 40], [660, 40], [900, 40]],
        [[500, 80]],
    ];

    private const ANIMAUX = [
        ['Moka', 'CH', 'Mme Borel'], ['Tigrou', 'CT', 'M. Jaccard'], ['Nemo', 'LP', 'Mme Pittet'],
        ['Rocky', 'CH', 'M. Favre'], ['Mimi', 'CT', 'Mme Rochat'], ['Olaf', 'CH', 'M. Schmid'],
    ];

    public function run(CalculateurCreneaux $calculateur): void
    {
        $cliniques = Clinique::withoutGlobalScopes()->whereIn('nom', ['Deux Rives', 'Le Lac'])->get();

        foreach ($cliniques as $clinique) {
            $fuseau = $clinique->fuseau_horaire ?? 'Europe/Zurich';
            $demain = CarbonImmutable::today($fuseau)->addDay();
            $deja = RendezVous::withoutGlobalScopes()->where('clinique_id', $clinique->id)
                ->where('reason', self::MOTIF)->where('starts_at', '>=', $demain->utc())->exists();
            if ($deja) {
                continue;
            }

            $horaires = HoraireOuverture::withoutGlobalScope('clinique')->where('clinique_id', $clinique->id)->first()
                ?? new HoraireOuverture(HoraireOuverture::DEFAUTS);
            $lignes = LignePlanning::withoutGlobalScope('clinique')->enService()->where('clinique_id', $clinique->id)->get();

            foreach (range(0, 13) as $i) {
                $jour = $demain->addDays($i);
                if ($calculateur->bornesDuJour($horaires, $jour)['fermee']) {
                    continue;
                }
                foreach (self::PLAGES[$i % count(self::PLAGES)] as $n => [$debut, $duree]) {
                    foreach ($lignes as $l => $ligne) {
                        [$nom, $espece, $proprietaire] = self::ANIMAUX[($i + $n + $l) % count(self::ANIMAUX)];
                        RendezVous::create([
                            'clinique_id' => $clinique->id,
                            'ligne_planning_id' => $ligne->id,
                            'patient_name' => $nom,
                            'species' => Espece::from($espece),
                            'owner_name' => $proprietaire,
                            'type' => TypeRendezVous::Consultation,
                            'status' => StatutRendezVous::Prevu,
                            'flags' => [],
                            'reason' => self::MOTIF,
                            'starts_at' => $jour->addMinutes($debut)->utc(),
                            'ends_at' => $jour->addMinutes($debut + $duree)->utc(),
                        ]);
                    }
                }
            }
        }
    }
}
