<?php

namespace Database\Seeders;

use App\Auth\RolesParDefaut;
use App\Enums\TypeSalle;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\Organisation;
use App\Models\Salle;
use App\Models\Societe;
use App\Services\PanelsLaboParDefaut;
use App\Services\PrestationsParDefaut;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Hiérarchie Organisation → Sociétés → Cliniques du front (org.js),
 * horaires par clinique (défauts du store settings) et salles
 * (consultation, bloc, imagerie — bloc.js et constantes planning).
 */
class CliniqueSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Abonnement SaaS : formule Cabinet et usage du mois ; pas de carte
        // (elle s'enregistre chez Stripe depuis Réglages › Facturation).
        $organisation = Organisation::create([
            'nom' => 'Deux Rives Vétérinaires',
            'plan' => 'cabinet',
            'cycle' => 'mois',
            'usage_emails' => 3412,
            'usage_sms' => 1000,
            'usage_stockage_go' => 18.4,
        ]);

        // Organisation de démo ouverte : vérifiée et dans une période payée
        // (sans facture inventée), sinon l'application resterait fermée.
        $organisation->forceFill([
            'verification' => 'verifiee',
            'verifiee_le' => now(),
            'statut_abonnement' => 'actif',
            'periode_debut' => today(),
            'periode_fin' => today()->addMonth(),
            'ancre_jour' => today()->day,
        ])->save();

        // Le seeder coupe les événements de modèle : les rôles par
        // défaut, le catalogue de prestations et le référentiel de
        // panels labo de l'organisation sont créés explicitement.
        RolesParDefaut::creer($organisation);
        PrestationsParDefaut::creer($organisation);
        PanelsLaboParDefaut::creer($organisation);

        $s1 = Societe::create(['organisation_id' => $organisation->id, 'nom' => 'Deux Rives Vétérinaires SA', 'ide' => 'CHE-214.694.581']);
        $s2 = Societe::create(['organisation_id' => $organisation->id, 'nom' => 'Vet Lac Léman Sàrl', 'ide' => 'CHE-302.118.447']);

        $deuxRives = Clinique::create([
            'societe_id' => $s1->id,
            'nom' => 'Deux Rives',
            'raison_sociale' => 'Clinique Vétérinaire des Deux Rives',
            'rue' => 'Rue du Simplon 24',
            'npa' => '1006',
            'ville' => 'Lausanne',
            'latitude' => 46.516,
            'longitude' => 6.629,
            'tel' => '+41 21 601 42 42',
            'email' => 'deuxrives@deuxrives.ch',
            'nombre_boxes' => 8,
        ]);

        $leLac = Clinique::create([
            'societe_id' => $s2->id,
            'nom' => 'Le Lac',
            'raison_sociale' => 'Clinique Vétérinaire du Lac',
            'rue' => 'Quai Gustave-Ador 14',
            'npa' => '1207',
            'ville' => 'Genève',
            'latitude' => 46.207,
            'longitude' => 6.162,
            'tel' => '+41 22 736 20 20',
            'email' => 'lelac@deuxrives.ch',
            'nombre_boxes' => 6,
        ]);

        foreach ([$deuxRives, $leLac] as $clinique) {
            HoraireOuverture::create([
                'clinique_id' => $clinique->id,
                'ouverture' => '08:00',
                'fermeture' => '19:30',
                'fermeture_samedi' => '15:00',
                'samedi_ferme' => false,
                'dimanche_ferme' => true,
                'pause_active' => true,
                'pause_debut' => '12:30',
                'pause_fin' => '14:00',
            ]);
        }

        $salles = [
            [$deuxRives, 'Consultation 1', TypeSalle::Consultation],
            [$deuxRives, 'Consultation 2', TypeSalle::Consultation],
            [$deuxRives, 'Consultation 3', TypeSalle::Consultation],
            [$deuxRives, 'Salle de soins', TypeSalle::Consultation],
            [$deuxRives, 'Bloc 1', TypeSalle::Bloc],
            [$deuxRives, 'Bloc 2', TypeSalle::Bloc],
            [$deuxRives, 'Radio / Écho', TypeSalle::Imagerie],
            [$leLac, 'Consultation 1', TypeSalle::Consultation],
            [$leLac, 'Consultation 2', TypeSalle::Consultation],
            [$leLac, 'Bloc 1', TypeSalle::Bloc],
        ];

        foreach ($salles as [$clinique, $nom, $type]) {
            Salle::create(['clinique_id' => $clinique->id, 'nom' => $nom, 'type' => $type]);
        }

        // Abonnement : aucune carte ni facture inventée ; la carte s'enregistre
        // dans Réglages › Facturation (Stripe, mode test en local).
    }
}
