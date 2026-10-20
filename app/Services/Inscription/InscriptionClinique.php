<?php

namespace App\Services\Inscription;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Enums\StatutContrat;
use App\Enums\VerificationOrganisation;
use App\Mail\CliniqueRefusee;
use App\Mail\CliniqueVerifiee;
use App\Mail\VerificationCliniqueAFaire;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\HoraireOuverture;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\Societe;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'une clinique par un vétérinaire, depuis son compte :
 * l'organisation, sa société, sa première clinique (horaires de départ)
 * et le vétérinaire comme administrateur. Le GLN est vérifié dans MedReg
 * avant ; s'il ne l'est pas, la plateforme reçoit un e-mail pour valider
 * ou refuser à la main. L'organisation reste fermée tant qu'elle n'est ni
 * vérifiée ni payée (Organisation::fermeture).
 */
final class InscriptionClinique
{
    public function __construct(private VerificationVeterinaire $verification) {}

    /**
     * @param  array{prenom: string, nom: string, gln: string, societe: array{nom: string, ide?: ?string}, clinique: array{nom: string, rue: string, npa: string, ville: string, tel?: ?string, email?: ?string}}  $donnees
     *
     * @throws ValidationException
     */
    public function inscrire(User $user, array $donnees): Employe
    {
        $this->exigerAucuneInscriptionEnCours($user);

        $resultat = $this->verification->verifier($donnees['gln'], $donnees['prenom'], $donnees['nom'], $user);
        $verifiee = $resultat->estVerifie();

        // La nouvelle organisation naît hors de toute autre : aucun
        // contexte clinique ne doit filtrer ni signer ses créations.
        app(ContexteClinique::class)->oublier();

        $employe = DB::transaction(function () use ($user, $donnees, $resultat, $verifiee): Employe {
            $nom = trim($donnees['prenom'].' '.$donnees['nom']);
            $user->forceFill(['name' => $nom])->save();

            if ($verifiee) {
                $this->retenirGln($user, $donnees['gln'], $resultat->fiche?->id);
            }

            // Les rôles par défaut, les prestations et les panels labo
            // naissent avec l'organisation (événement du modèle).
            $organisation = Organisation::create(['nom' => $donnees['clinique']['nom']]);
            $organisation->forceFill([
                'verification' => $verifiee ? VerificationOrganisation::Verifiee : VerificationOrganisation::EnAttente,
                'verification_raison' => $resultat->raison,
                'verification_gln' => $donnees['gln'],
                'verifiee_le' => $verifiee ? now() : null,
                'inscrite_par' => $user->id,
            ])->save();

            $societe = Societe::create([
                'organisation_id' => $organisation->id,
                'nom' => $donnees['societe']['nom'],
                'ide' => $donnees['societe']['ide'] ?? null,
            ]);

            $clinique = Clinique::create([
                'societe_id' => $societe->id,
                'nom' => $donnees['clinique']['nom'],
                'raison_sociale' => $donnees['clinique']['nom'],
                'rue' => $donnees['clinique']['rue'],
                'npa' => $donnees['clinique']['npa'],
                'ville' => $donnees['clinique']['ville'],
                'tel' => $donnees['clinique']['tel'] ?? null,
                'email' => $donnees['clinique']['email'] ?? null,
            ]);

            // Hors de tout contexte clinique : les événements des modèles
            // rattachés à une clinique sont contournés, la clinique est posée.
            HoraireOuverture::withoutEvents(fn () => HoraireOuverture::create([
                'clinique_id' => $clinique->id,
                ...HoraireOuverture::DEFAUTS,
            ]));

            return Employe::withoutEvents(fn (): Employe => Employe::create([
                'clinique_id' => $clinique->id,
                'user_id' => $user->id,
                'nom' => 'Dr '.$nom,
                'initiales' => Str::upper(Str::substr($donnees['prenom'], 0, 1).Str::substr($donnees['nom'], 0, 1)),
                'role_libelle' => RolesParDefaut::VETERINAIRE,
                'role_id' => Role::withoutGlobalScope('organisation')
                    ->where('organisation_id', $organisation->id)
                    ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                    ->value('id'),
                'taux' => 100,
                'contrat_statut' => StatutContrat::Signe,
                'entree_le' => today(),
                'email' => $user->email,
            ]));
        });

        if (! $verifiee) {
            $this->demanderVerification($employe->clinique->societe->organisation, $resultat);
        }

        return $employe;
    }

    /** Vérification à la main : la clinique s'ouvre (reste à choisir une formule). */
    public function valider(Organisation $organisation): void
    {
        if ($organisation->verification !== VerificationOrganisation::EnAttente) {
            return;
        }

        DB::transaction(function () use ($organisation): void {
            $organisation->forceFill([
                'verification' => VerificationOrganisation::Verifiee,
                'verifiee_le' => now(),
                'verification_note' => null,
            ])->save();

            $user = $organisation->inscritePar;
            $gln = $organisation->verification_gln;

            if ($user && $gln && ! User::where('gln', $gln)->whereKeyNot($user->id)->exists()) {
                $this->retenirGln($user, $gln, null);
            }
        });

        $this->prevenir($organisation, new CliniqueVerifiee($organisation));
    }

    public function refuser(Organisation $organisation, ?string $note): void
    {
        if ($organisation->verification !== VerificationOrganisation::EnAttente) {
            return;
        }

        $organisation->forceFill([
            'verification' => VerificationOrganisation::Refusee,
            'verification_note' => $note ?: null,
        ])->save();

        $this->prevenir($organisation, new CliniqueRefusee($organisation));
    }

    /**
     * MedReg était en panne à l'inscription : nouvel essai automatique.
     * Vérifiée cette fois, la clinique s'ouvre ; un autre écart reste à la
     * main (l'e-mail déjà envoyé suffit).
     */
    public function reessayer(Organisation $organisation): bool
    {
        $user = $organisation->inscritePar;

        if ($organisation->verification !== VerificationOrganisation::EnAttente
            || $organisation->verification_raison !== 'medreg_indisponible'
            || ! $user || ! $organisation->verification_gln) {
            return false;
        }

        $resultat = $this->verification->verifier($organisation->verification_gln, $user->prenom(), $user->nomFamille(), $user);

        if ($resultat->raison === 'medreg_indisponible') {
            return false;
        }

        if (! $resultat->estVerifie()) {
            $organisation->forceFill(['verification_raison' => $resultat->raison])->save();

            return false;
        }

        DB::transaction(function () use ($organisation, $user, $resultat): void {
            $organisation->forceFill([
                'verification' => VerificationOrganisation::Verifiee,
                'verifiee_le' => now(),
                'verification_raison' => null,
            ])->save();
            $this->retenirGln($user, (string) $organisation->verification_gln, $resultat->fiche?->id);
        });

        $this->prevenir($organisation, new CliniqueVerifiee($organisation));

        return true;
    }

    /**
     * Une seule inscription à la fois : une clinique qui attend sa
     * vérification ou sa première formule se termine d'abord.
     *
     * @throws ValidationException
     */
    private function exigerAucuneInscriptionEnCours(User $user): void
    {
        $enCours = Organisation::query()
            ->where('inscrite_par', $user->id)
            ->where(fn ($requete) => $requete
                ->where('verification', VerificationOrganisation::EnAttente)
                ->orWhere(fn ($verifiee) => $verifiee
                    ->where('verification', VerificationOrganisation::Verifiee)
                    ->whereNull('statut_abonnement')))
            ->first();

        if ($enCours) {
            throw ValidationException::withMessages([
                'inscription' => $enCours->verification === VerificationOrganisation::EnAttente
                    ? "Votre clinique « {$enCours->nom} » attend encore sa vérification."
                    : "Votre clinique « {$enCours->nom} » attend encore sa formule : choisissez-la avant d’en inscrire une autre.",
            ]);
        }
    }

    private function retenirGln(User $user, string $gln, ?int $medregId): void
    {
        $user->forceFill(['gln' => $gln, 'medreg_id' => $medregId, 'gln_verifie_le' => now()])->save();
    }

    private function demanderVerification(Organisation $organisation, ResultatVerification $resultat): void
    {
        $adresse = config('plateforme.verification_email');

        if (! $adresse) {
            // Sans adresse, la clinique attendrait sans que personne le sache
            Log::warning('Clinique à vérifier à la main, mais aucune adresse PLATEFORME_VERIFICATION_EMAIL.', ['organisation' => $organisation->id]);

            return;
        }

        Mail::to($adresse)->send(new VerificationCliniqueAFaire($organisation, $resultat));
    }

    private function prevenir(Organisation $organisation, Mailable $mail): void
    {
        $email = $organisation->inscritePar?->email;

        if ($email) {
            Mail::to($email)->send($mail);
        }
    }
}
