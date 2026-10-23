<?php

namespace App\Services\Inscription;

use App\Enums\DecisionVerification;
use App\Enums\StatutIdentite;
use App\Enums\StatutVerificationVeterinaire;
use App\Mail\VerificationVeterinaireAFaire;
use App\Models\Organisation;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleIdentite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Vérification d'identité d'un vétérinaire par Stripe Identity
 * (23.10.2026, décisions de Jim) : pièce d'identité et selfie sur la page
 * de Stripe, puis le nom lu sur la pièce comparé à celui que MedReg donne
 * pour son GLN. Le même nom : identité vérifiée, badge du vétérinaire, et
 * les cliniques qu'il a inscrites s'ouvrent pour de bon.
 *
 * Deux chemins : l'inscription d'une clinique (la vérification est portée
 * sur la prochaine facture de l'organisation, 1.50 CHF) ; le badge seul,
 * payé d'avance par le vétérinaire (2 CHF). Une seule tentative par
 * personne : un échec chez Stripe ou un autre nom passe à la main, et la
 * plateforme le voit dans ses vétérinaires à vérifier.
 */
final class VerificationIdentite
{
    /** Prix du badge payé par le vétérinaire : la vérification et les frais de carte */
    public const int PRIX_BADGE_CENTIMES = 200;

    /** Ce que Stripe facture une vérification réussie, porté sur la facture de l'organisation */
    public const int FRAIS_CENTIMES = 150;

    /** Jours d'ouverture d'une clinique inscrite avant la vérification d'identité */
    public const int DELAI_JOURS = 7;

    public const array RAISONS = [
        'nom_different' => 'Le nom lu sur la pièce d’identité n’est pas celui que MedReg donne pour ce GLN.',
        'introuvable' => 'Ce GLN n’est pas celui d’un vétérinaire dans MedReg.',
        'gln_deja_utilise' => 'Ce GLN est déjà vérifié sur un autre compte.',
        'medreg_indisponible' => 'MedReg n’a pas répondu au moment de comparer les noms.',
        'echec' => 'Stripe n’a pas pu vérifier la pièce d’identité ou le selfie.',
    ];

    public function __construct(
        private PasserelleIdentite $stripe,
        private VerificationVeterinaire $verification,
    ) {}

    /** @return array<string, mixed> */
    public function etat(User $user): array
    {
        $organisation = $this->organisationEnAttente($user);

        return [
            'statut' => $user->identite_statut?->value,
            'raison' => $user->identite_raison ? self::RAISONS[explode(':', $user->identite_raison)[0]] ?? null : null,
            'verifiee_le' => $user->identite_verifiee_le?->toIso8601String(),
            'payee' => $user->identite_payee_le !== null,
            // Inscrit une clinique : la vérification va sur la facture de l'organisation
            'sur_facture' => $organisation !== null,
            'avant_le' => $organisation?->identite_avant_le?->toDateString(),
            'prix_centimes' => self::PRIX_BADGE_CENTIMES,
        ];
    }

    /**
     * L'adresse de la page de Stripe où la personne montre sa pièce : une
     * seule session par personne, rouverte tant qu'elle n'a rien envoyé.
     *
     * @throws ValidationException
     */
    public function demarrer(User $user): string
    {
        match ($user->identite_statut) {
            StatutIdentite::Verifiee => throw ValidationException::withMessages(['identite' => 'Votre identité est déjà vérifiée.']),
            StatutIdentite::ARevoir => throw ValidationException::withMessages(['identite' => 'Votre vérification se termine à la main : la plateforme vous répond bientôt.']),
            default => null,
        };
        if (! $user->gln) {
            throw ValidationException::withMessages(['gln' => 'Indiquez d’abord votre GLN dans votre profil.']);
        }
        if ($user->identite_payee_le === null && $this->organisationEnAttente($user) === null) {
            throw ValidationException::withMessages(['identite' => 'Réglez d’abord la vérification de votre identité.']);
        }

        try {
            if ($user->identite_session_id) {
                $session = $this->stripe->lireSession($user->identite_session_id);
                if ($session['statut'] === 'requires_input' && $session['erreur'] === null && $session['url']) {
                    return $session['url'];
                }
                // L'issue est déjà là (le webhook n'est pas encore passé)
                $this->appliquer($user, $session);

                throw ValidationException::withMessages(['identite' => $session['statut'] === 'processing'
                    ? 'Stripe vérifie encore vos documents : le résultat arrive dans quelques minutes.'
                    : 'Votre vérification est terminée : rechargez la page.']);
            }

            $session = $this->stripe->creerSession(
                'user-'.$user->id,
                rtrim((string) config('app.frontend_url'), '/').'/identite',
                ['user_id' => (string) $user->id, 'plateforme' => 'veterinary'],
            );
        } catch (ErreurPaiement $e) {
            throw ValidationException::withMessages(['identite' => $e->getMessage()]);
        }

        $user->forceFill(['identite_statut' => StatutIdentite::EnCours, 'identite_session_id' => $session['id']])->save();

        return (string) $session['url'];
    }

    /** Webhook `identity.verification_session.*` (ou relecture) : l'issue de la session. */
    public function surSession(string $sessionId): void
    {
        $user = User::query()->where('identite_session_id', $sessionId)->first();
        if (! $user || $user->identite_statut !== StatutIdentite::EnCours) {
            return;
        }

        $this->appliquer($user, $this->stripe->lireSession($sessionId));
    }

    /**
     * Vérifiée à la main par un modérateur : les cliniques du vétérinaire
     * s'ouvrent pour de bon. La vérification n'est facturée que si Stripe
     * l'a réussie (un autre nom que MedReg).
     */
    public function validerALaMain(User $user): void
    {
        if ($user->identite_statut === StatutIdentite::Verifiee) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $facturee = $user->identite_nom !== null && $user->identite_payee_le === null;
            $user->forceFill(['identite_statut' => StatutIdentite::Verifiee, 'identite_raison' => 'manuelle', 'identite_verifiee_le' => now()])->save();
            $this->ouvrirOrganisations($user, $facturee);
        });
    }

    /** La clinique inscrite par cette personne qui attend son identité, s'il y en a une. */
    public function organisationEnAttente(User $user): ?Organisation
    {
        return Organisation::query()->where('inscrite_par', $user->id)->whereNotNull('identite_avant_le')->orderBy('id')->first();
    }

    public static function delai(): CarbonImmutable
    {
        return CarbonImmutable::today('Europe/Zurich')->addDays(self::DELAI_JOURS);
    }

    /** @param  array{id: string, statut: string, url: ?string, erreur: ?string, prenom: ?string, nom: ?string}  $session */
    private function appliquer(User $user, array $session): void
    {
        if ($session['statut'] === 'verified') {
            $this->conclure($user, (string) $session['prenom'], (string) $session['nom']);

            return;
        }

        // Une seule tentative : un échec passe à la main
        if ($session['statut'] === 'requires_input' && $session['erreur'] !== null) {
            try {
                $this->stripe->annulerSession($session['id']);
            } catch (ErreurPaiement $e) {
                report($e);
            }
            $this->aRevoir($user, 'echec:'.$session['erreur'], null);
        }
    }

    private function conclure(User $user, string $prenom, string $nom): void
    {
        $lu = trim($prenom.' '.$nom);
        $resultat = $this->verification->verifier((string) $user->gln, $prenom, $nom, $user);

        if (! $resultat->estVerifie()) {
            $this->aRevoir($user, (string) $resultat->raison, $lu);

            return;
        }

        DB::transaction(function () use ($user, $lu, $resultat): void {
            User::libererGlnNonVerifie((string) $user->gln, $user);
            $user->forceFill([
                'identite_statut' => StatutIdentite::Verifiee,
                'identite_nom' => $lu,
                'identite_raison' => null,
                'identite_verifiee_le' => now(),
                'gln_verifie_le' => $user->gln_verifie_le ?? now(),
                'medreg_id' => $resultat->fiche->id ?? $user->medreg_id,
            ])->save();

            // La même personne que le GLN : le badge du vétérinaire
            if ($user->verification_veterinaire !== StatutVerificationVeterinaire::Verifie) {
                $user->consignerVerification(DecisionVerification::Verifie, StatutVerificationVeterinaire::Verifie, 'Identité vérifiée par Stripe Identity, au nom donné par MedReg.');
            }

            $this->ouvrirOrganisations($user, $user->identite_payee_le === null);
        });
    }

    /** Passe à la main : la plateforme la voit dans ses vétérinaires à vérifier, et reçoit un e-mail. */
    private function aRevoir(User $user, string $raison, ?string $lu): void
    {
        DB::transaction(function () use ($user, $raison, $lu): void {
            $user->forceFill(['identite_statut' => StatutIdentite::ARevoir, 'identite_raison' => $raison, 'identite_nom' => $lu])->save();
            if ($user->verification_veterinaire !== StatutVerificationVeterinaire::Verifie) {
                $user->consignerVerification(
                    DecisionVerification::Demandee,
                    StatutVerificationVeterinaire::EnAttente,
                    'Stripe Identity : '.(self::RAISONS[explode(':', $raison)[0]] ?? $raison).($lu ? ' Nom lu sur la pièce : '.$lu.'.' : ''),
                );
            }
        });

        $destinataire = config('plateforme.verification_email');
        if ($destinataire) {
            Mail::to($destinataire)->send(new VerificationVeterinaireAFaire($user->refresh()));
        } else {
            Log::warning('Identité à vérifier à la main, mais aucune adresse PLATEFORME_VERIFICATION_EMAIL.', ['user' => $user->id]);
        }
    }

    /** Les cliniques inscrites qui attendaient l'identité s'ouvrent pour de bon ; la première porte la vérification. */
    private function ouvrirOrganisations(User $user, bool $facturer): void
    {
        Organisation::query()->where('inscrite_par', $user->id)->whereNotNull('identite_avant_le')->orderBy('id')->get()
            ->each(function (Organisation $organisation) use (&$facturer): void {
                $organisation->forceFill([
                    'identite_avant_le' => null,
                    'verification_raison' => $organisation->verification_raison === 'identite_requise' ? null : $organisation->verification_raison,
                    'frais_identite_centimes' => $facturer ? self::FRAIS_CENTIMES : $organisation->frais_identite_centimes,
                ])->save();
                $facturer = false;
            });
    }
}
