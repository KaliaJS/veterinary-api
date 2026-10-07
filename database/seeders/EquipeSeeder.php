<?php

namespace Database\Seeders;

use App\Auth\RolesParDefaut;
use App\Enums\RoleLignePlanning;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\Salle;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Équipe, lignes du planning et absences du front (planning.seed.js :
 * STAFF_SEED, LANES_SEED, ABSENCES_SEED). Les offsets de jours des
 * absences deviennent des dates absolues, ancrées sur aujourd'hui.
 */
class EquipeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $leLac = Clinique::where('nom', 'Le Lac')->firstOrFail();
        $anna = User::where('email', 'a.meyer@deuxrives.ch')->first();

        $staff = [
            // [clé, clinique, user, nom, initiales, rôle, taux, contrat, entrée, tel, mail, droit, pris]
            ['st1', $deuxRives, $anna?->id, 'Dr Anna Meyer', 'AM', 'Vétérinaire', 100, 'CDI', '03.2019', '079 412 88 31', 'a.meyer@deuxrives.ch', 25, 12],
            ['st2', $deuxRives, null, 'Dr Rachel Solal', 'RS', 'Vétérinaire', 80, 'CDI', '09.2021', '078 233 90 12', 'r.solal@deuxrives.ch', 20, 9],
            ['st3', $deuxRives, null, 'Dr Kofi Adjei', 'KA', 'Vétérinaire', 100, 'CDI', '01.2023', '076 554 02 77', 'k.adjei@deuxrives.ch', 25, 15],
            ['st4', $deuxRives, null, 'Julie Muller', 'JM', 'ASV · Accueil', 100, 'CDI', '06.2020', '079 811 45 09', 'j.muller@deuxrives.ch', 25, 11],
            ['st5', $deuxRives, null, 'Marc Besson', 'MB', 'ASV · Bloc', 60, 'CDI', '02.2022', '077 340 66 84', 'm.besson@deuxrives.ch', 15, 4],
            ['st6', $leLac, null, 'Léa Fontaine', 'LF', 'ASV', 80, 'CDD → 12.2026', '11.2024', '078 902 13 55', 'l.fontaine@laveyre.ch', 20, 13],
        ];

        $organisation = $deuxRives->societe->organisation;
        $roleVeterinaire = $organisation->roles()->where('nom', RolesParDefaut::VETERINAIRE)->firstOrFail();
        $roleAsv = $organisation->roles()->where('nom', RolesParDefaut::ASV)->firstOrFail();
        // Compte de démonstration (Anna Meyer) : Administrateur, pour accéder
        // à Organisation et au Site web depuis l'application.
        $roleAdministrateur = $organisation->roles()->where('nom', RolesParDefaut::ADMINISTRATEUR)->firstOrFail();

        $employes = [];
        foreach ($staff as [$cle, $clinique, $userId, $nom, $initiales, $role, $taux, $contrat, $entree, $tel, $mail, $droit, $pris]) {
            $employes[$cle] = Employe::create([
                'clinique_id' => $clinique->id,
                'user_id' => $userId,
                'nom' => $nom,
                'initiales' => $initiales,
                'role_libelle' => $role,
                'role_id' => match (true) {
                    $userId !== null && $userId === $anna?->id => $roleAdministrateur->id,
                    str_starts_with($role, 'Vétérinaire') => $roleVeterinaire->id,
                    default => $roleAsv->id,
                },
                'taux' => $taux,
                'contrat' => $contrat,
                'entree_le' => Carbon::createFromFormat('m.Y', $entree)->startOfMonth(),
                'tel' => $tel,
                'email' => $mail,
                'conges_droit' => $droit,
                'conges_pris' => $pris,
            ]);
        }

        $bloc1 = Salle::where('clinique_id', $deuxRives->id)->where('nom', 'Bloc 1')->firstOrFail();
        $radioEcho = Salle::where('clinique_id', $deuxRives->id)->where('nom', 'Radio / Écho')->firstOrFail();

        $lignes = [
            // [employé, salle, nom, sous-titre, rôle, initiales, groupe]
            [$employes['st1'], null, 'Dr Meyer', 'CONSULTATION', RoleLignePlanning::Consultation, 'AM', 'Consultation'],
            [$employes['st2'], null, 'Dr Solal', 'CONSULTATION', RoleLignePlanning::Consultation, 'RS', 'Consultation'],
            [$employes['st3'], null, 'Dr Adjei', 'CONSULT · URGENCES', RoleLignePlanning::Consultation, 'KA', 'Consultation'],
            [null, $bloc1, 'Bloc 1', 'CHIRURGIE', RoleLignePlanning::Bloc, 'B1', 'Chirurgie'],
            [null, $radioEcho, 'Radio / Écho', 'IMAGERIE', RoleLignePlanning::Imagerie, 'IM', 'Imagerie'],
        ];

        foreach ($lignes as $ordre => [$employe, $salle, $nom, $sousTitre, $role, $initiales, $groupe]) {
            LignePlanning::create([
                'clinique_id' => $deuxRives->id,
                'employe_id' => $employe?->id,
                'salle_id' => $salle?->id,
                'nom' => $nom,
                'sous_titre' => $sousTitre,
                'role' => $role,
                'initiales' => $initiales,
                'groupe' => $groupe,
                'ordre' => $ordre + 1,
            ]);
        }

        $absences = [
            // [employé, type, offset début, offset fin, statut, note]
            ['st2', TypeAbsence::Conge, 2, 6, StatutAbsence::Validee, 'Vacances familiales'],
            ['st3', TypeAbsence::Garde, 5, 6, StatutAbsence::Validee, 'Garde week-end — urgences'],
            ['st6', TypeAbsence::Maladie, 0, 1, StatutAbsence::Validee, 'Certificat reçu — grippe'],
            ['st4', TypeAbsence::Conge, 9, 13, StatutAbsence::EnAttente, 'Demande via l’app — mariage de sa sœur'],
            ['st1', TypeAbsence::Formation, 8, 8, StatutAbsence::Validee, 'Congrès SVK-ASMPA — cardiologie'],
            ['st5', TypeAbsence::Recuperation, 3, 3, StatutAbsence::EnAttente, 'Récup heures bloc du 28.07'],
            ['st2', TypeAbsence::Conge, -32, -26, StatutAbsence::Validee, null],
        ];

        foreach ($absences as [$cle, $type, $debut, $fin, $statut, $note]) {
            Absence::create([
                'employe_id' => $employes[$cle]->id,
                'type' => $type,
                'debut_le' => today()->addDays($debut),
                'fin_le' => today()->addDays($fin),
                'statut' => $statut,
                'note' => $note,
                'valide_par_user_id' => $statut === StatutAbsence::Validee ? $anna?->id : null,
            ]);
        }
    }
}
