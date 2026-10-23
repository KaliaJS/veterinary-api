<?php

namespace App\Services\Inscription;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Le vétérinaire qui inscrit une clinique est-il bien celui qu'il dit ?
 * Son GLN doit être celui d'un vétérinaire dans MedReg, au même nom, et
 * d'aucun autre compte. Un GLN est public : la vérification écarte les
 * robots et les fautes, elle ne prouve pas l'identité ; tout écart passe
 * à la main.
 */
final class VerificationVeterinaire
{
    public function __construct(private MedReg $medreg) {}

    public function verifier(string $gln, string $prenom, string $nom, ?User $compte = null): ResultatVerification
    {
        // Seul un GLN déjà vérifié chez un autre compte bloque : un GLN
        // seulement saisi ne réserve rien (23.10.2026, sinon n'importe qui
        // pouvait bloquer le GLN d'un vrai vétérinaire)
        $pris = User::query()
            ->where('gln', $gln)
            ->whereNotNull('gln_verifie_le')
            ->when($compte, fn ($requete) => $requete->whereKeyNot($compte->id))
            ->exists();

        if ($pris) {
            return new ResultatVerification('gln_deja_utilise');
        }

        try {
            $fiche = $this->medreg->chercher($gln);
        } catch (MedRegIndisponible) {
            return new ResultatVerification('medreg_indisponible');
        }

        if ($fiche === null) {
            return new ResultatVerification('introuvable');
        }

        return new ResultatVerification(self::memeNom($fiche, $prenom, $nom) ? null : 'nom_different', $fiche);
    }

    /**
     * Tous les mots du nom MedReg et le premier prénom se retrouvent dans
     * le nom déclaré : accents, majuscules, traits d'union, second prénom
     * ou titre (« Dr ») n'y changent rien.
     */
    public static function memeNom(FicheMedReg $fiche, string $prenom, string $nom): bool
    {
        $mots = fn (string $texte): array => array_values(array_filter(
            preg_split('/[^a-z]+/', Str::lower(Str::ascii($texte))) ?: [],
        ));

        $declares = $mots($prenom.' '.$nom);
        $noms = $mots($fiche->nom);
        $prenoms = $mots($fiche->prenom);

        return $noms !== [] && $prenoms !== []
            && array_diff($noms, $declares) === []
            && in_array($prenoms[0], $declares, true);
    }

    /** Le contrôle de GS1 : 13 chiffres, le dernier calculé sur les douze autres. */
    public static function glnValide(string $gln): bool
    {
        if (preg_match('/^\d{13}$/', $gln) !== 1) {
            return false;
        }

        $somme = 0;
        for ($i = 0; $i < 12; $i++) {
            $somme += (int) $gln[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $somme % 10) % 10 === (int) $gln[12];
    }
}
