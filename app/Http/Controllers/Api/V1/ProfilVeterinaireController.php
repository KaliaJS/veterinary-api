<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DecisionVerification;
use App\Enums\Specialite;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\ModifierFichePubliqueRequest;
use App\Http\Requests\V1\Auth\ModifierProfilVeterinaireRequest;
use App\Models\ParcoursVeterinaire;
use App\Models\SpecialiteVeterinaire;
use App\Models\User;
use App\Services\Inscription\VerificationIdentite;
use App\Services\Inscription\VerificationVeterinaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Profil vétérinaire de son propre compte (Réglages, Profil) : son GLN,
 * cherché dans MedReg au même nom, et ses spécialités, qu'il gère
 * lui-même et qui le suivent dans toutes ses cliniques. Un GLN est
 * public : le registre écarte les fautes et les inventions, il ne
 * prouve pas l'identité (vérification manuelle de la plateforme, à
 * part). Sa fiche publique de l'annuaire : présentation, langues et
 * parcours (postes et formations), qu'il écrit lui-même.
 */
class ProfilVeterinaireController extends Controller
{
    public function __construct(private VerificationVeterinaire $verification) {}

    public function afficher(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profil($request->user())]);
    }

    public function modifier(ModifierProfilVeterinaireRequest $request): JsonResponse
    {
        $compte = $request->user();
        $gln = $request->validated('gln');
        $registre = null;

        if ($gln !== $compte->gln) {
            // Le badge vaut pour un GLN : en changer, c'est à revérifier
            if ($compte->verification_veterinaire !== null) {
                $compte->consignerVerification(DecisionVerification::Retire, null, 'GLN modifié par le vétérinaire');
            }
            if ($gln === null) {
                $compte->forceFill(['gln' => null, 'medreg_id' => null, 'gln_verifie_le' => null])->save();
            } else {
                $resultat = $this->verification->verifier($gln, $compte->prenom(), $compte->nomFamille(), $compte);
                if ($resultat->raison === 'gln_deja_utilise') {
                    throw ValidationException::withMessages(['gln' => 'Ce GLN est déjà celui d’un autre compte. Écrivez-nous si c’est le vôtre.']);
                }
                // Inconnu de MedReg : pas un GLN de vétérinaire, refusé et pas
                // enregistré (24.10.2026)
                if ($resultat->raison === 'introuvable') {
                    throw ValidationException::withMessages(['gln' => 'Ce GLN n’est celui d’aucun vétérinaire dans MedReg. Vérifiez les 13 chiffres.']);
                }
                // Gardé sinon (nom différent : un modérateur vérifie à la main ;
                // MedReg indisponible : revérifié plus tard) ;
                // « trouvé au registre » seulement si MedReg confirme le nom.
                // Un autre compte qui l'avait saisi sans vérification le perd
                // (23.10.2026 : saisir un GLN ne le réserve pas)
                User::libererGlnNonVerifie($gln, $compte);
                $compte->forceFill([
                    'gln' => $gln,
                    'medreg_id' => $resultat->fiche?->id,
                    'gln_verifie_le' => $resultat->raison === null ? now() : null,
                ])->save();
                $registre = $resultat->raison ?? 'trouve';
            }
        }

        $voulues = $request->validated('specialites');
        DB::transaction(function () use ($compte, $voulues): void {
            $compte->specialites()->whereNotIn('specialite', $voulues)->delete();
            foreach ($voulues as $specialite) {
                $compte->specialites()->firstOrCreate(['specialite' => $specialite]);
            }
        });

        return response()->json(['data' => [...$this->profil($compte->fresh()), 'registre_resultat' => $registre]]);
    }

    /** Accord pour paraître nommément dans l'annuaire de Veterinary, donné ou retiré à tout moment (nLPD). */
    public function annuaire(Request $request): JsonResponse
    {
        $accord = $request->validate(['accord' => ['required', 'boolean']])['accord'];
        $compte = $request->user();
        $compte->forceFill(['annuaire_accord_le' => $accord ? ($compte->annuaire_accord_le ?? now()) : null])->save();

        return response()->json(['data' => $this->profil($compte->fresh())]);
    }

    /** Fiche publique : présentation, langues, et tout le parcours d'un coup (dans l'ordre reçu). */
    public function modifierFiche(ModifierFichePubliqueRequest $request): JsonResponse
    {
        $compte = $request->user();
        $donnees = $request->validated();

        DB::transaction(function () use ($compte, $donnees): void {
            $compte->forceFill([
                'presentation' => trim((string) ($donnees['presentation'] ?? '')) ?: null,
                'langues' => array_values($donnees['langues']),
            ])->save();
            $compte->parcours()->delete();
            foreach (array_values($donnees['parcours']) as $ordre => $ligne) {
                $compte->parcours()->create([
                    'type' => $ligne['type'],
                    'titre' => trim($ligne['titre']),
                    'lieu' => trim((string) ($ligne['lieu'] ?? '')) ?: null,
                    'ville' => trim((string) ($ligne['ville'] ?? '')) ?: null,
                    'annee_debut' => $ligne['annee_debut'] ?? null,
                    'annee_fin' => $ligne['annee_fin'] ?? null,
                    'ordre' => $ordre,
                ]);
            }
        });

        return response()->json(['data' => $this->profil($compte->fresh())]);
    }

    /** @return array<string, mixed> */
    private function profil(User $compte): array
    {
        $derniere = $compte->decisionsVerification()->first();

        return [
            'gln' => $compte->gln,
            // Trouvé dans MedReg au nom du compte
            'gln_au_registre' => $compte->gln_verifie_le !== null,
            // Identité vérifiée par Stripe Identity (23.10.2026)
            'identite' => app(VerificationIdentite::class)->etat($compte),
            // Badge : par l'identité, ou décidé par la plateforme
            'verification' => [
                'statut' => $compte->verification_veterinaire?->value,
                'le' => $compte->verification_veterinaire_le?->toIso8601String(),
                'note' => $derniere?->decision === DecisionVerification::Refuse || $derniere?->decision === DecisionVerification::Retire ? $derniere->note : null,
            ],
            'specialites' => $compte->specialites()->get()
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
            // D'accord pour paraître nommément dans l'annuaire (nLPD)
            'annuaire_accord' => $compte->annuaire_accord_le !== null,
            // Fiche publique de l'annuaire
            'presentation' => $compte->presentation,
            'langues' => $compte->langues ?? [],
            'parcours' => $compte->parcours()->get()->map(fn (ParcoursVeterinaire $ligne): array => $ligne->versTableau())->all(),
        ];
    }
}
