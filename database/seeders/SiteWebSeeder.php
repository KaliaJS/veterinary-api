<?php

namespace Database\Seeders;

use App\Models\Employe;
use App\Models\Organisation;
use App\Models\SiteWeb;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Site web publié de l'organisation de démonstration (Deux Rives et
 * Le Lac) : accroche, services, équipe reprise des vétérinaires et
 * ASV, deux actualités. Adresses et horaires restent ceux des cliniques.
 */
class SiteWebSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $cliniques = $organisation->cliniques()->pluck('cliniques.nom', 'cliniques.id');

        SiteWeb::withoutGlobalScopes()->updateOrCreate(['organisation_id' => $organisation->id], [
            'slug' => 'deux-rives',
            'est_publie' => true,
            'couleur' => 'sauge',
            'accroche' => 'Des soins attentifs pour vos compagnons, à Lausanne et Genève.',
            'presentation' => "Deux cliniques, une même équipe : consultations, chirurgie, hospitalisation et urgences pour chiens, chats et NAC.\nPrenez rendez-vous en ligne, nous vous accueillons du lundi au samedi.",
            'email' => 'accueil@deuxrives.ch',
            'tel_urgences' => '+41 21 555 01 99',
            'services' => [
                ...SiteWeb::SERVICES_DE_DEPART,
                ['titre' => 'Dentisterie', 'description' => 'Détartrage, extractions et suivi de l’hygiène bucco-dentaire.'],
            ],
            'equipe' => Employe::withoutGlobalScopes()
                ->whereIn('clinique_id', $cliniques->keys())
                ->orderBy('nom')
                ->get()
                ->map(fn (Employe $employe): array => [
                    'nom' => $employe->nom,
                    'role' => $employe->role_libelle,
                    'bio' => null,
                    'clinique' => $cliniques[$employe->clinique_id],
                ])
                ->values()
                ->all(),
            'actualites' => [
                ['titre' => 'Campagne de vaccination d’automne', 'texte' => "Rappels CHPPiL et leucose à tarif préférentiel jusqu’au 31 octobre.\nPrenez rendez-vous en ligne ou à l’accueil.", 'date' => '2026-10-01'],
                ['titre' => 'Nouvel échographe à Le Lac', 'texte' => 'Nos examens d’imagerie se font désormais sur place, sans délai.', 'date' => '2026-09-15'],
            ],
        ]);
    }
}
