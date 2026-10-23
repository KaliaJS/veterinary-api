<?php

namespace App\Http\Controllers\Api\V1\Plateforme;

use App\Enums\DecisionVerification;
use App\Enums\Specialite;
use App\Enums\StatutVerificationVeterinaire;
use App\Http\Controllers\Controller;
use App\Models\DecisionVerification as Ligne;
use App\Models\Employe;
use App\Models\SpecialiteVeterinaire;
use App\Models\User;
use App\Services\Inscription\MedReg;
use App\Services\Inscription\MedRegIndisponible;
use App\Services\Inscription\VerificationIdentite;
use App\Services\Inscription\VerificationVeterinaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vérification manuelle des vétérinaires (espace Plateforme, réservé
 * aux modérateurs) : la file des demandes, la fiche d'un vétérinaire
 * avec MedReg consulté en direct, et la décision (vérifié, refusé,
 * badge retiré), gardée dans l'historique.
 */
class VeterinairePlateformeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('statut', StatutVerificationVeterinaire::EnAttente->value)->toString();
        abort_unless(in_array($statut, array_column(StatutVerificationVeterinaire::cases(), 'value'), true), 422, 'Statut inconnu.');

        $comptes = User::query()
            ->where('verification_veterinaire', $statut)
            ->with('specialites')
            ->orderBy('verification_veterinaire_le', $statut === StatutVerificationVeterinaire::EnAttente->value ? 'asc' : 'desc')
            ->get();

        return response()->json(['data' => $comptes->map(fn (User $compte): array => $this->resume($compte))->values()]);
    }

    public function show(User $user, MedReg $medreg): JsonResponse
    {
        $registre = null;
        if ($user->gln) {
            try {
                $fiche = $medreg->chercher($user->gln);
                $registre = $fiche === null
                    ? ['statut' => 'introuvable']
                    : ['statut' => VerificationVeterinaire::memeNom($fiche, $user->prenom(), $user->nomFamille()) ? 'meme_nom' : 'nom_different', ...$fiche->toArray()];
            } catch (MedRegIndisponible) {
                $registre = ['statut' => 'indisponible'];
            }
        }

        return response()->json(['data' => [
            ...$this->resume($user->load('specialites')),
            'registre' => $registre,
            'historique' => $user->decisionsVerification()->with('moderateur')->get()->map(fn (Ligne $ligne): array => [
                'decision' => $ligne->decision->value,
                'libelle' => $ligne->decision->libelle(),
                'note' => $ligne->note,
                'par' => $ligne->moderateur?->name,
                'le' => $ligne->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function decider(Request $request, User $user): JsonResponse
    {
        $donnees = $request->validate([
            'decision' => ['required', Rule::in([DecisionVerification::Verifie->value, DecisionVerification::Refuse->value, DecisionVerification::Retire->value])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $decision = DecisionVerification::from($donnees['decision']);

        // Jamais sa propre vérification : un autre modérateur s'en charge
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['decision' => 'Un autre modérateur doit vérifier votre propre compte.']);
        }

        if ($decision !== DecisionVerification::Verifie && blank($donnees['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Dites pourquoi : le vétérinaire verra ce motif.']);
        }
        if ($decision === DecisionVerification::Verifie && ! $user->gln) {
            throw ValidationException::withMessages(['decision' => 'Ce compte n’a pas de GLN.']);
        }

        $user->consignerVerification($decision, match ($decision) {
            DecisionVerification::Verifie => StatutVerificationVeterinaire::Verifie,
            DecisionVerification::Refuse => StatutVerificationVeterinaire::Refuse,
            default => null,
        }, $donnees['note'] ?? null, $request->user());

        // Vérifié à la main : son identité l'est aussi, ses cliniques s'ouvrent pour de bon
        if ($decision === DecisionVerification::Verifie) {
            app(VerificationIdentite::class)->validerALaMain($user);
        }

        return $this->show($user->fresh(), app(MedReg::class));
    }

    /** @return array<string, mixed> */
    private function resume(User $compte): array
    {
        $fiches = Employe::withoutGlobalScopes()
            ->where('user_id', $compte->id)
            ->with(['organisation', 'cliniques'])
            ->get();

        return [
            'id' => $compte->id,
            'nom' => $compte->name,
            'email' => $compte->email,
            'gln' => $compte->gln,
            'gln_au_registre' => $compte->gln_verifie_le !== null,
            'statut' => $compte->verification_veterinaire?->value,
            'le' => $compte->verification_veterinaire_le?->toIso8601String(),
            // Stripe Identity : à revoir à la main, avec le nom lu sur la pièce
            'identite' => [
                'statut' => $compte->identite_statut?->value,
                'nom_lu' => $compte->identite_nom,
                'raison' => $compte->identite_raison,
            ],
            'specialites' => $compte->specialites
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
            // Où il travaille : organisation, poste, cliniques
            'equipes' => $fiches->map(fn (Employe $fiche): array => [
                'organisation' => $fiche->organisation?->nom,
                'poste' => $fiche->role_libelle,
                'cliniques' => $fiche->cliniques->pluck('nom')->all(),
            ])->values()->all(),
        ];
    }
}
