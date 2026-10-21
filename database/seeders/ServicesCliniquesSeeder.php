<?php

namespace Database\Seeders;

use App\Enums\Espece;
use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use App\Enums\Specialite;
use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Database\Seeder;

/**
 * Services, animaux soignés et spécialités de démonstration. Deux Rives
 * propose tout, Le Lac presque tout, le Cabinet du Jorat est rural ; les
 * cliniques de l'annuaire reçoivent des profils variés mais toujours les
 * mêmes (tirés de leur nom). Idempotent : rejouable sur une base remplie.
 */
class ServicesCliniquesSeeder extends Seeder
{
    private const PROFILS = [
        'Deux Rives' => [
            'services' => ['hospitalisation', 'chirurgie', 'imagerie', 'laboratoire', 'boutique', 'teleconsultation', 'urgences', 'domicile'],
            'urgences' => ModaliteUrgences::Permanence,
            'especes' => ['CH', 'CT', 'LP', 'FU', 'RG', 'OI', 'RP'],
        ],
        'Le Lac' => [
            'services' => ['hospitalisation', 'chirurgie', 'imagerie', 'laboratoire', 'boutique', 'teleconsultation', 'urgences'],
            'urgences' => ModaliteUrgences::Horaires,
            'especes' => ['CH', 'CT', 'LP', 'RG', 'OI'],
        ],
        'Cabinet du Jorat' => [
            'services' => ['domicile', 'teleconsultation', 'laboratoire'],
            'urgences' => null,
            'especes' => ['CH', 'CT', 'CV', 'BV', 'OV', 'CP'],
        ],
    ];

    public function run(): void
    {
        Clinique::withoutGlobalScopes()->orderBy('id')->get()->each(function (Clinique $clinique): void {
            $profil = self::PROFILS[$clinique->nom] ?? $this->profilVarie($clinique);

            foreach ($profil['services'] as $code) {
                $service = ServiceClinique::from($code);
                $clinique->services()->firstOrCreate(['service' => $service], [
                    'modalite' => $service === ServiceClinique::Urgences ? ($profil['urgences'] ?? ModaliteUrgences::Horaires) : null,
                ]);
            }
            if (in_array('hospitalisation', $profil['services'], true) && $clinique->nombre_boxes === 0) {
                $clinique->update(['nombre_boxes' => 4]);
            }
            foreach ($profil['especes'] as $espece) {
                $clinique->especes()->firstOrCreate(['espece' => Espece::from($espece)]);
            }
        });

        // Une ou deux spécialités pour la plupart des vétérinaires
        Employe::withoutGlobalScopes()->orderBy('id')->get()
            ->filter(fn (Employe $employe): bool => $employe->estVeterinaire())
            ->each(function (Employe $employe): void {
                $graine = crc32($employe->nom.$employe->id);
                $toutes = Specialite::cases();
                $nombre = $graine % 4 === 0 ? 0 : ($graine % 3 === 0 ? 2 : 1);
                for ($i = 0; $i < $nombre; $i++) {
                    $employe->specialites()->firstOrCreate(['specialite' => $toutes[($graine >> (4 * $i)) % count($toutes)]]);
                }
            });
    }

    /** @return array{services: list<string>, urgences: ?ModaliteUrgences, especes: list<string>} */
    private function profilVarie(Clinique $clinique): array
    {
        $graine = crc32($clinique->nom);
        $bit = fn (int $n): bool => (bool) (($graine >> $n) & 1);

        $services = array_values(array_filter([
            $clinique->nombre_boxes > 0 && $bit(0) ? 'hospitalisation' : null,
            $bit(1) || $bit(2) ? 'chirurgie' : null,
            $bit(3) || $bit(4) ? 'imagerie' : null,
            $bit(5) ? 'laboratoire' : null,
            $bit(6) ? 'boutique' : null,
            $bit(7) || $bit(8) ? 'teleconsultation' : null,
            $bit(9) ? 'urgences' : null,
            $bit(10) && $bit(11) ? 'domicile' : null,
        ]));

        $especes = ['CH', 'CT'];
        if ($bit(12)) {
            array_push($especes, 'LP', 'RG', 'FU');
        }
        if ($bit(13)) {
            array_push($especes, 'OI', 'RP');
        }
        if ($bit(14) && $bit(15)) {
            array_push($especes, 'CV');
        }

        return [
            'services' => $services,
            'urgences' => $bit(16) ? ModaliteUrgences::Permanence : ModaliteUrgences::Horaires,
            'especes' => $especes,
        ];
    }
}
