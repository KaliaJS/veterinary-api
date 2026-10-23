<?php

namespace Database\Seeders;

use App\Enums\DecisionVerification;
use App\Enums\Espece;
use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use App\Enums\Specialite;
use App\Enums\StatutVerificationVeterinaire;
use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Database\Seeder;

/**
 * Services, animaux soignés et spécialités de démonstration. Deux Rives
 * propose tout, Le Lac presque tout, le Cabinet du Jorat est rural ; les
 * cliniques de l'annuaire reçoivent des profils variés mais toujours les
 * mêmes (tirés de leur nom). Vétérinaires du Léman ne montre pas ses
 * vétérinaires dans l'annuaire (seulement leurs spécialités) ; Le Lac
 * accepte les rendez-vous sans compte. Idempotent : rejouable sur une base
 * remplie.
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

    /** Clinique qui garde ses vétérinaires hors de l'annuaire */
    private const SANS_VETERINAIRES_AFFICHES = 'Vétérinaires du Léman';

    /** Clinique qui accepte les demandes de rendez-vous sans compte */
    private const RDV_SANS_COMPTE = 'Le Lac';

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
            if ($clinique->nom === self::SANS_VETERINAIRES_AFFICHES) {
                $clinique->update(['affiche_veterinaires' => false]);
            }
            if ($clinique->nom === self::RDV_SANS_COMPTE) {
                $clinique->update(['rdv_sans_compte' => true]);
            }
        });

        // Une ou deux spécialités pour la plupart des vétérinaires (sur leur compte),
        // et la vérification de la plateforme pour la plupart
        Employe::withoutGlobalScopes()->orderBy('id')->get()
            ->filter(fn (Employe $employe): bool => $employe->estVeterinaire())
            ->each(function (Employe $employe): void {
                $graine = crc32($employe->nom.$employe->id);
                $toutes = Specialite::cases();
                $nombre = $graine % 4 === 0 ? 0 : ($graine % 3 === 0 ? 2 : 1);
                // Deux sur trois sont vérifiés par la plateforme (l'annuaire ne montre qu'eux)
                if ($graine % 3 !== 1 && $employe->user->verification_veterinaire === null) {
                    $employe->user->consignerVerification(DecisionVerification::Verifie, StatutVerificationVeterinaire::Verifie, 'Données de démonstration');
                }
                // Les vérifiés de la démonstration ont accepté d'être nommés dans l'annuaire (nLPD)
                if ($employe->user->estVerifie() && $employe->user->annuaire_accord_le === null) {
                    $employe->user->forceFill(['annuaire_accord_le' => now()])->save();
                }
                for ($i = 0; $i < $nombre; $i++) {
                    // Sur le compte du vétérinaire : il les déclare lui-même
                    $employe->user->specialites()->firstOrCreate(['specialite' => $toutes[($graine >> (4 * $i)) % count($toutes)]]);
                }
                $this->fichePublique($employe, $graine);
            });
    }

    private const FACULTES = [
        ['Vetsuisse, Université de Berne', 'Berne'],
        ['Vetsuisse, Université de Zurich', 'Zurich'],
        ['École nationale vétérinaire de Lyon', 'Lyon'],
        ['Université de Liège', 'Liège'],
    ];

    private const POSTES = [
        ['Vétérinaire assistant', 'Clinique vétérinaire des Alpes', 'Sion'],
        ['Interne en médecine des petits animaux', 'Hôpital vétérinaire universitaire', 'Berne'],
        ['Vétérinaire', 'Cabinet vétérinaire du Vieux-Bourg', 'Neuchâtel'],
        ['Vétérinaire urgentiste', 'Centre d’urgences vétérinaires', 'Genève'],
    ];

    /**
     * Fiche publique de démonstration : langues selon la région de la
     * clinique, une présentation, une formation et un ancien poste. Une
     * fiche déjà remplie n'est pas touchée.
     */
    private function fichePublique(Employe $employe, int $graine): void
    {
        $compte = $employe->user;
        if ($compte->presentation !== null || $compte->parcours()->exists()) {
            return;
        }
        $npa = (int) ($employe->cliniques()->withoutGlobalScopes()->first()?->npa ?? 1000);
        $langues = match (true) {
            $npa >= 6500 && $npa < 7000 => ['it', 'fr', 'de'],
            $npa >= 3000 => ['de', 'fr', 'en'],
            default => ['fr', 'en'],
        };
        if ($graine % 5 === 0) {
            $langues[] = 'es';
        }
        $diplome = 2004 + $graine % 15;
        [$faculte, $villeFaculte] = self::FACULTES[$graine % count(self::FACULTES)];
        [$poste, $lieu, $ville] = self::POSTES[($graine >> 3) % count(self::POSTES)];

        $compte->forceFill([
            'presentation' => 'La médecine des petits animaux est mon métier : je prends le temps d’expliquer chaque étape aux propriétaires.',
            'langues' => array_values(array_unique($langues)),
        ])->save();
        $compte->parcours()->create(['type' => 'experience', 'titre' => $poste, 'lieu' => $lieu, 'ville' => $ville, 'annee_debut' => $diplome, 'annee_fin' => $diplome + 2 + $graine % 4, 'ordre' => 0]);
        $compte->parcours()->create(['type' => 'formation', 'titre' => 'Doctorat en médecine vétérinaire', 'lieu' => $faculte, 'ville' => $villeFaculte, 'annee_fin' => $diplome, 'ordre' => 1]);
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
