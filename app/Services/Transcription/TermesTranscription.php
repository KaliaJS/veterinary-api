<?php

namespace App\Services\Transcription;

use App\Models\EnregistrementConsultation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mots rares à faire reconnaître pour une consultation. Deepgram n'en
 * accepte qu'environ cent (500 jetons au total) : le catalogue entier ne
 * passe pas, on choisit, dans l'ordre :
 * 1. la liste de l'organisation (ses réglages) ;
 * 2. la race du patient ;
 * 3. ses vaccins et ses médicaments récents ;
 * 4. les produits que l'organisation prescrit le plus.
 * Le modèle connaît déjà le vocabulaire médical courant : ces mots servent
 * aux noms de marque, aux produits suisses, aux races peu connues.
 */
final class TermesTranscription
{
    /** Marge sous les 500 jetons de Deepgram, compte pessimiste (3 caractères par jeton). */
    private const int BUDGET_JETONS = 420;

    /** @return list<string> */
    public function pour(EnregistrementConsultation $enregistrement): array
    {
        $rendezVous = $enregistrement->rendezVous;
        $patientId = $rendezVous?->patient_id;
        $race = $patientId ? DB::table('patients')->where('id', $patientId)->value('race') : null;

        return self::retenir([
            ...($enregistrement->organisation->termes_transcription ?? []),
            $race,
            ...($patientId ? $this->vaccins($patientId) : []),
            ...($patientId ? $this->medicamentsDuPatient($patientId) : []),
            ...$this->produitsPrescrits($enregistrement->organisation_id),
        ]);
    }

    /**
     * Sans doublon ni mot trop court, dans la limite du budget.
     *
     * @param  array<int, string|null>  $candidats
     * @return list<string>
     */
    public static function retenir(array $candidats): array
    {
        $termes = [];
        $vus = [];
        $jetons = 0;
        foreach ($candidats as $candidat) {
            $terme = trim((string) preg_replace('/\s+/u', ' ', (string) $candidat));
            $cle = Str::lower(Str::ascii($terme));
            if (mb_strlen($terme) < 3 || isset($vus[$cle])) {
                continue;
            }
            $cout = (int) ceil(mb_strlen($terme) / 3);
            if ($jetons + $cout > self::BUDGET_JETONS) {
                break;
            }
            $vus[$cle] = true;
            $jetons += $cout;
            $termes[] = $terme;
        }

        return $termes;
    }

    /** « Metacam 1,5 mg/ml ad us. vet., suspension orale » devient « Metacam ». */
    public static function nomCourt(string $nom): string
    {
        $court = preg_split('/[\d,(\/;]|\bad us\b/iu', $nom)[0] ?? $nom;

        return implode(' ', array_slice(preg_split('/\s+/u', trim($court)) ?: [], 0, 3));
    }

    /** @return list<string> */
    private function vaccins(int $patientId): array
    {
        return DB::table('vaccinations')
            ->where('patient_id', $patientId)
            ->where('injectee_le', '>=', now()->subYears(3)->toDateString())
            ->orderByDesc('injectee_le')
            ->get(['nom', 'marque'])
            ->map(fn ($vaccin) => self::nomCourt((string) ($vaccin->marque ?: $vaccin->nom)))
            ->all();
    }

    /** @return list<string> */
    private function medicamentsDuPatient(int $patientId): array
    {
        return $this->lignesPrescrites()
            ->where('ordonnances.patient_id', $patientId)
            ->where('ordonnances.created_at', '>=', now()->subYear())
            ->orderByDesc('ordonnances.created_at')
            ->pluck('produits.nom')
            ->map(fn (string $nom) => self::nomCourt($nom))
            ->all();
    }

    /** @return list<string> */
    private function produitsPrescrits(int $organisationId): array
    {
        return $this->lignesPrescrites()
            ->where('ordonnances.organisation_id', $organisationId)
            ->where('ordonnances.created_at', '>=', now()->subDays(90))
            ->groupBy('produits.nom')
            ->orderByRaw('count(*) desc')
            ->limit(40)
            ->pluck('produits.nom')
            ->map(fn (string $nom) => self::nomCourt($nom))
            ->all();
    }

    private function lignesPrescrites(): Builder
    {
        return DB::table('ordonnance_lignes')
            ->join('ordonnances', 'ordonnances.id', '=', 'ordonnance_lignes.ordonnance_id')
            ->join('produits', 'produits.id', '=', 'ordonnance_lignes.produit_id');
    }
}
