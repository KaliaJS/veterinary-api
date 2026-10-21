<?php

namespace Database\Seeders;

use App\Auth\RolesParDefaut;
use App\Enums\RoleLignePlanning;
use App\Enums\StatutContrat;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Organisation;
use App\Models\Societe;
use App\Services\Photos\PhotosCliniques;
use Database\Seeders\Concerns\ComptesDeDemo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Annuaire de démonstration : des cliniques fictives à travers la Suisse,
 * plus serrées autour de Lausanne (regroupements, noms qui se gênent)
 * (romande, alémanique, italienne), chacune dans sa propre organisation
 * ouverte, avec un vétérinaire et sa colonne de planning (des créneaux
 * réels dans l'annuaire) et une photo. Les cliniques de Deux Rives
 * reçoivent aussi une photo. Photos libres d'Unsplash (licence Unsplash :
 * usage libre, commercial compris), téléchargées une fois dans storage,
 * jamais dans le dépôt. Rejouable : rien n'est créé deux fois.
 */
class AnnuaireSeeder extends Seeder
{
    use ComptesDeDemo;
    use WithoutModelEvents;

    /** Photos Unsplash (identifiants) : consultations, bloc, laboratoire, animaux à la clinique. */
    private const array PHOTOS = [
        '1770836037289-e00e5f351d11', '1551076805-e1869033e561', '1770836037183-e0b4471fe2c0',
        '1700665537650-1bf37979aae0', '1602052577122-f73b9710adba', '1770836037793-95bdbf190f71',
        '1644675272883-0c4d582528d8', '1654895716780-b4664497420d', '1700665537604-412e89a285c3',
        '1770836037275-38b44e4b101f', '1596272875729-ed2ff7d6d9c5', '1733783489145-f3d3ee7a9ccf',
        '1770836037816-4445dbd449fd', '1630438994394-3deff7a591bf', '1791395275751-4f1ca7daef4a',
        '1553688738-a278b9f063e0', '1770836037949-c5e8db65aa86', '1759164955427-14ca448a839d',
    ];

    /** [nom, rue, NPA, ville, latitude, longitude, téléphone, vétérinaire] */
    private const array CLINIQUES = [
        ['Clinique vétérinaire des Pâquis', 'Rue de Berne 32', '1201', 'Genève', 46.2112, 6.1468, '+41 22 731 18 40', 'Dre Claire Rochat'],
        ['Cabinet vétérinaire de la Côte', 'Rue de la Gare 18', '1260', 'Nyon', 46.3829, 6.2372, '+41 22 361 52 10', 'Dr Julien Favre'],
        ['Vétérinaires du Léman', 'Avenue de Marcelin 9', '1110', 'Morges', 46.5128, 6.4931, '+41 21 801 36 22', 'Dre Sophie Jaquet'],
        ['Clinique vétérinaire du Nord vaudois', 'Rue des Remparts 7', '1400', 'Yverdon-les-Bains', 46.7792, 6.6432, '+41 24 425 61 40', 'Dr Marc Pittet'],
        // Lausanne : trois cliniques proches avec Deux Rives, regroupées en dézoomant
        ['Clinique vétérinaire d’Ouchy', 'Avenue d’Ouchy 58', '1006', 'Lausanne', 46.5121, 6.6302, '+41 21 616 24 30', 'Dre Camille Rossier'],
        ['Cabinet vétérinaire de la Pontaise', 'Rue de la Pontaise 21', '1018', 'Lausanne', 46.5309, 6.6262, '+41 21 646 13 52', 'Dr Hugo Blanc'],
        // Lausanne et ses abords, à quelques rues les unes des autres : regroupements et noms qui se gênent
        ['Clinique vétérinaire du Flon', 'Rue du Port-Franc 16', '1003', 'Lausanne', 46.5214, 6.6281, '+41 21 312 40 18', 'Dre Léa Monod'],
        ['Vétérinaires de la Gare', 'Avenue de la Gare 29', '1003', 'Lausanne', 46.5179, 6.6327, '+41 21 320 55 61', 'Dr Samuel Rey'],
        ['Cabinet vétérinaire de la Harpe', 'Avenue de la Harpe 12', '1007', 'Lausanne', 46.5108, 6.6229, '+41 21 617 32 04', 'Dre Inès Perret'],
        ['Cabinet vétérinaire de Chailly', 'Avenue de Chailly 30', '1012', 'Lausanne', 46.5274, 6.6508, '+41 21 652 18 77', 'Dr Victor Gaillard'],
        ['Clinique vétérinaire de Malley', 'Route de Renens 4', '1008', 'Prilly', 46.5298, 6.6052, '+41 21 624 41 26', 'Dre Manon Aubert'],
        ['Cabinet vétérinaire de Renens', 'Rue de Lausanne 25', '1020', 'Renens', 46.5391, 6.5886, '+41 21 634 27 83', 'Dr Noah Cuendet'],
        ['Clinique vétérinaire de Lavaux', 'Avenue de Lavaux 30', '1009', 'Pully', 46.5104, 6.6611, '+41 21 728 15 49', 'Dre Zoé Bovet'],
        ['Clinique vétérinaire de la Riviera', 'Avenue des Alpes 54', '1820', 'Montreux', 46.4335, 6.9108, '+41 21 963 27 15', 'Dre Laure Chevalley'],
        ['Clinique vétérinaire du Littoral', 'Rue de l’Hôpital 11', '2000', 'Neuchâtel', 46.9925, 6.9311, '+41 32 725 40 12', 'Dr Olivier Matthey'],
        ['Cabinet vétérinaire des Montagnes', 'Avenue Léopold-Robert 66', '2300', 'La Chaux-de-Fonds', 47.1012, 6.8287, '+41 32 913 55 21', 'Dre Anne Huguenin'],
        ['Clinique vétérinaire de la Sarine', 'Boulevard de Pérolles 24', '1700', 'Fribourg', 46.8023, 7.1513, '+41 26 322 48 16', 'Dr Pierre Clerc'],
        ['Vétérinaires de la Gruyère', 'Grand-Rue 21', '1630', 'Bulle', 46.6178, 7.0592, '+41 26 912 33 70', 'Dre Émilie Pasquier'],
        ['Clinique vétérinaire du Valais central', 'Avenue de la Gare 30', '1950', 'Sion', 46.2295, 7.3588, '+41 27 322 15 64', 'Dr Nicolas Fournier'],
        ['Cabinet vétérinaire du Coude du Rhône', 'Avenue du Grand-Saint-Bernard 12', '1920', 'Martigny', 46.0996, 7.0741, '+41 27 722 46 30', 'Dre Valérie Darbellay'],
        ['Cabinet vétérinaire du Jura', 'Rue de la Préfecture 6', '2800', 'Delémont', 47.3638, 7.3441, '+41 32 422 61 18', 'Dr Thomas Chételat'],
        ['Tierarztpraxis Aare', 'Monbijoustrasse 41', '3011', 'Bern', 46.9431, 7.4392, '+41 31 371 24 60', 'Dr. Lukas Meier'],
        ['Tierklinik Thunersee', 'Frutigenstrasse 15', '3600', 'Thun', 46.7536, 7.6295, '+41 33 222 41 87', 'Dr. Anna Zbinden'],
        ['Tierklinik Seeland', 'Zentralstrasse 47', '2502', 'Biel/Bienne', 47.1395, 7.2499, '+41 32 322 73 05', 'Dr. Martin Gerber'],
        ['Tierarztpraxis am Rhein', 'Clarastrasse 28', '4058', 'Basel', 47.5634, 7.5946, '+41 61 691 42 33', 'Dr. Sarah Vogt'],
        ['Tierarztpraxis Weissenstein', 'Werkhofstrasse 9', '4500', 'Solothurn', 47.2104, 7.5371, '+41 32 622 18 46', 'Dr. Daniel Kunz'],
        ['Tierklinik Aarau West', 'Bahnhofstrasse 62', '5000', 'Aarau', 47.3911, 8.0468, '+41 62 822 55 14', 'Dr. Nina Hofmann'],
        ['Tierklinik Pilatus', 'Obergrundstrasse 37', '6003', 'Luzern', 47.0463, 8.3017, '+41 41 210 36 82', 'Dr. Reto Bucher'],
        ['Tierarztpraxis Zugersee', 'Baarerstrasse 19', '6300', 'Zug', 47.1693, 8.5171, '+41 41 711 47 20', 'Dr. Claudia Iten'],
        ['Tierklinik Limmat', 'Josefstrasse 104', '8005', 'Zürich', 47.3843, 8.5297, '+41 44 271 63 50', 'Dr. Jonas Keller'],
        ['Tierarztpraxis Eulachtal', 'Technikumstrasse 22', '8400', 'Winterthur', 47.4966, 8.7259, '+41 52 212 39 41', 'Dr. Petra Brunner'],
        ['Tierarztpraxis Munot', 'Vordergasse 51', '8200', 'Schaffhausen', 47.6973, 8.6388, '+41 52 625 18 33', 'Dr. Stefan Wanner'],
        ['Tierklinik Säntis', 'Rosenbergstrasse 30', '9000', 'St. Gallen', 47.4258, 9.3708, '+41 71 222 64 17', 'Dr. Andrea Frei'],
        ['Tierarztpraxis Calanda', 'Gürtelstrasse 26', '7000', 'Chur', 46.8521, 9.5262, '+41 81 252 41 90', 'Dr. Gian Casutt'],
        ['Clinica veterinaria del Ceresio', 'Via Pretorio 12', '6900', 'Lugano', 46.0059, 8.9493, '+41 91 922 36 41', 'Dr.ssa Giulia Bernasconi'],
        ['Studio veterinario dei Castelli', 'Viale Stazione 21', '6500', 'Bellinzona', 46.1963, 9.0287, '+41 91 825 47 22', 'Dr. Marco Pedrazzini'],
    ];

    public function run(PhotosCliniques $photos): void
    {
        foreach (self::CLINIQUES as $rang => [$nom, $rue, $npa, $ville, $latitude, $longitude, $tel, $veterinaire]) {
            $clinique = Clinique::withoutGlobalScopes()->where('nom', $nom)->first()
                ?? $this->creer($nom, $rue, $npa, $ville, $latitude, $longitude, $tel, $veterinaire);
            $this->photographier($clinique, self::PHOTOS[$rang % count(self::PHOTOS)], $photos);
        }

        // Les cliniques de démonstration de Deux Rives ont aussi leur photo
        foreach (['Deux Rives' => '1700665537650-1bf37979aae0', 'Le Lac' => '1770836037183-e0b4471fe2c0'] as $nom => $photo) {
            if ($clinique = Clinique::withoutGlobalScopes()->where('nom', $nom)->first()) {
                $this->photographier($clinique, $photo, $photos);
            }
        }
    }

    private function creer(string $nom, string $rue, string $npa, string $ville, float $latitude, float $longitude, string $tel, string $veterinaire): Clinique
    {
        $organisation = Organisation::create(['nom' => $nom, 'plan' => 'cabinet', 'cycle' => 'mois']);
        // Ouverte : vérifiée et dans une période payée, comme Deux Rives
        $organisation->forceFill([
            'verification' => 'verifiee',
            'verifiee_le' => now(),
            'statut_abonnement' => 'actif',
            'periode_debut' => today(),
            'periode_fin' => today()->addMonth(),
            'ancre_jour' => today()->day,
        ])->save();
        RolesParDefaut::creer($organisation);

        $societe = Societe::create(['organisation_id' => $organisation->id, 'nom' => $nom.' Sàrl']);
        $slug = Str::slug($nom);
        $clinique = Clinique::create([
            'societe_id' => $societe->id,
            'nom' => $nom,
            'raison_sociale' => $nom,
            'rue' => $rue,
            'npa' => $npa,
            'ville' => $ville,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'tel' => $tel,
            'email' => $slug.'@demo.veterinary.app',
            'nombre_boxes' => 4,
        ]);
        HoraireOuverture::create(['clinique_id' => $clinique->id, ...HoraireOuverture::DEFAUTS]);

        // Un vétérinaire et sa colonne : l'annuaire calcule ses créneaux
        $employe = Employe::create([
            'organisation_id' => $organisation->id,
            'user_id' => $this->compteDeDemo($veterinaire, 'veterinaire.'.$slug.'@demo.veterinary.app'),
            'nom' => $veterinaire,
            'initiales' => Str::upper(collect(explode(' ', $veterinaire))->slice(-2)->map(fn (string $mot): string => Str::substr($mot, 0, 1))->implode('')),
            'role_libelle' => 'Vétérinaire',
            'role_id' => $organisation->roles()->where('nom', RolesParDefaut::VETERINAIRE)->value('id'),
            'taux' => 100,
            'contrat' => 'CDI',
            'contrat_statut' => StatutContrat::Signe,
            'entree_le' => today()->subYears(3),
            'conges_droit' => 25,
        ]);
        $employe->cliniques()->attach($clinique->id);
        LignePlanning::create([
            'clinique_id' => $clinique->id,
            'employe_id' => $employe->id,
            'nom' => $veterinaire,
            'sous_titre' => 'CONSULTATION',
            'role' => RoleLignePlanning::Consultation,
            'initiales' => $employe->initiales,
            'groupe' => 'Consultation',
            'ordre' => 1,
        ]);

        return $clinique;
    }

    /** Pose la photo si la clinique n'en a pas encore (téléchargée une fois, puis reprise du cache). */
    private function photographier(Clinique $clinique, string $photo, PhotosCliniques $photos): void
    {
        if ($clinique->photo_chemin !== null) {
            return;
        }

        $cache = 'demo/photos-cliniques/'.$photo.'.jpg';
        if (! Storage::disk('local')->exists($cache)) {
            $reponse = Http::timeout(30)->get('https://images.unsplash.com/photo-'.$photo, ['w' => 1600, 'q' => 80, 'fm' => 'jpg', 'fit' => 'crop']);
            if (! $reponse->successful()) {
                $this->command?->warn("Photo {$photo} introuvable : {$clinique->nom} reste hors de l'annuaire.");

                return;
            }
            Storage::disk('local')->put($cache, $reponse->body());
        }

        $fichier = new UploadedFile(Storage::disk('local')->path($cache), $photo.'.jpg', 'image/jpeg', null, true);
        $organisationId = (int) $clinique->societe()->value('organisation_id');
        $enregistree = $photos->enregistrer($fichier, $organisationId);
        $clinique->forceFill(['photo_chemin' => $enregistree['chemin'], 'photo_apercu' => $enregistree['apercu']])->save();
    }
}
