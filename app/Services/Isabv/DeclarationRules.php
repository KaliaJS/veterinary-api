<?php

namespace App\Services\Isabv;

use App\Enums\IsabvForm;
use App\Models\AntibioticDeclaration;
use App\Models\AntibioticDeclarationLine;
use App\Models\IsabvCode;
use App\Models\IsabvPreparation;
use App\Rules\NumeroBdta;
use Illuminate\Support\Collection;

/**
 * Ce qu'une déclaration doit porter pour partir (08.11.2026), d'après les
 * directives techniques de l'OSAV (ch. 12.7, règles de validation) et
 * l'annexe de l'O-SI ABV (ch. 2.1.5, 2.1.6, 2.2) :
 * - EH : espèce, poids, clé de diagnostic (au moins le niveau 1), et par
 *   préparation la quantité par administration, la fréquence, les jours,
 *   la consommation totale, la quantité remise ;
 * - EN : catégorie d'utilisation, n° BDTA, adresse de l'exploitation, clé
 *   de diagnostic (au moins le niveau 2), les mêmes champs par préparation ;
 * - AV : catégorie d'utilisation, n° BDTA, adresse, préparation, quantité
 *   remise, clé de diagnostic (niveau 2), et au moins deux des trois :
 *   dosage par animal et par jour, nombre d'animaux, nombre de jours ;
 * - FZL : espèce, adresse de l'établissement, préparation, quantité remise.
 * Un 2e dosage commencé doit être complet. Une préparation à substance
 * critique demande la confirmation du vétérinaire (Swagger des données de
 * base, `hasCriticalSubstance`). Un traitement oral d'un groupe plus grand
 * que celui de l'OMédV (art. 3, al. 1, let. d : plus de 10 animaux, 20
 * porcs, 50 volailles ou lapins) ne passe que par l'application web.
 */
class DeclarationRules
{
    /**
     * Ce qui manque, en mots, vide si la déclaration peut partir.
     *
     * @return list<string>
     */
    public function missing(AntibioticDeclaration $declaration): array
    {
        $form = $declaration->form;
        $missing = [];
        $species = trim((string) $declaration->species_code);
        if ($species === '' || ! $this->isLeafSpecies($species) || IsabvForm::forSpecies($species) !== ($form === IsabvForm::StockSupply ? IsabvForm::LivestockTreatment : $form)) {
            $missing[] = $form->isLivestock() ? 'la catégorie d’utilisation' : 'l’espèce';
        }
        if ($form === IsabvForm::CompanionTreatment && ! ($declaration->weight_kg > 0)) {
            $missing[] = 'le poids de l’animal';
        }
        if ($form->isLivestock()) {
            if (trim((string) $declaration->holding_number) === '') {
                $missing[] = 'le n° BDTA de l’unité d’élevage';
            } elseif (! NumeroBdta::isValid((string) $declaration->holding_number)) {
                $missing[] = 'un n° BDTA juste : 7 chiffres, le dernier est un chiffre de contrôle';
            }
            if (trim((string) $declaration->holding_address) === '') {
                $missing[] = 'l’adresse de l’exploitation';
            }
        }
        if ($form === IsabvForm::ZooLab && trim((string) $declaration->holding_address) === '') {
            $missing[] = 'l’adresse de l’établissement';
        }
        if ($form !== IsabvForm::ZooLab && ! $this->isDiagnosisValid($declaration)) {
            $missing[] = $form === IsabvForm::CompanionTreatment ? 'l’indication (clé de diagnostic)' : 'l’indication (clé de diagnostic, au moins deux niveaux)';
        }

        $lines = $declaration->relationLoaded('lines') ? $declaration->lines : $declaration->lines()->get();
        if ($lines->isEmpty()) {
            $missing[] = 'une préparation';
        }
        $preparations = IsabvPreparation::query()->whereIn('code', $lines->pluck('preparation_code')->filter()->all())->get()->keyBy('code');
        if ($lines->isNotEmpty() && ! $lines->contains(fn (AntibioticDeclarationLine $line) => $preparations->get((string) $line->preparation_code)?->must_transfer)) {
            $missing[] = 'une préparation de la liste de l’OSAV';
        }
        foreach ($lines as $index => $line) {
            $missing = [...$missing, ...$this->lineMissing($declaration, $line, $preparations, $lines->count() > 1 ? $index + 1 : null)];
        }
        // Un groupe d'animaux de rente : la voie dit s'il est oral (seulement par l'application web)
        if ($form === IsabvForm::LivestockTreatment && (int) $declaration->animals_count > self::groupSize((string) $declaration->species_code)
            && $lines->contains(fn (AntibioticDeclarationLine $line) => $line->route_code === null)) {
            $missing[] = 'la voie d’administration (traitement de groupe)';
        }
        if ($form === IsabvForm::StockSupply) {
            $given = count(array_filter([$declaration->av_daily_dose > 0, $declaration->animals_count > 0, $declaration->av_treatment_days > 0]));
            if ($given < 2) {
                $missing[] = 'deux des trois : dosage par animal et par jour, nombre d’animaux, nombre de jours';
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Pourquoi la déclaration ne peut pas partir par Veterinary, même
     * complète : un traitement oral d'un groupe d'animaux de rente plus grand
     * que celui de l'OMédV se saisit dans l'application web de l'OSAV
     * (directives, ch. 2.8 et 4.1).
     */
    public function webOnlyReason(AntibioticDeclaration $declaration): ?string
    {
        $lines = $declaration->relationLoaded('lines') ? $declaration->lines : $declaration->lines()->get();

        return $this->isLargeOralGroup($declaration, $lines)
            ? 'Traitement oral d’un groupe de plus de '.self::groupSize((string) $declaration->species_code).' animaux : il se saisit dans l’application web de l’OSAV, pas par Veterinary.'
            : null;
    }

    /**
     * Consommation totale, formule des directives de l'OSAV (ch. 12.2) :
     * ((⌊(jours − 1) × fréquence + 0,01⌋ + ⌈fréquence⌉) × quantité × animaux),
     * pour chaque dosage.
     */
    public static function totalQuantity(?float $dose, ?int $days, ?float $perDay, int $animals = 1): ?float
    {
        if ($dose === null || $days === null || $perDay === null || $dose <= 0 || $days < 1 || $perDay <= 0) {
            return null;
        }
        $administrations = floor(($days - 1) * $perDay + 0.01) + ceil($perDay);

        return round($administrations * $dose * max(1, $animals), 4);
    }

    /** Une espèce ou catégorie d'utilisation sans subdivision (« 2.1.5 », pas « 2.1 ») */
    public function isLeafSpecies(string $code): bool
    {
        if (! IsabvCode::query()->ofKind(IsabvCode::KIND_SPECIES)->where('code', $code)->exists()) {
            return false;
        }

        return ! IsabvCode::query()->ofKind(IsabvCode::KIND_SPECIES)->where('parent_code', $code)->exists();
    }

    /** L'espèce des clés de diagnostic : « 2.1.5 » se diagnostique comme « 2.1 » */
    public static function diagnosisSpecies(string $species): string
    {
        return implode('.', array_slice(explode('.', $species), 0, 2));
    }

    private function isDiagnosisValid(AntibioticDeclaration $declaration): bool
    {
        $key = trim((string) $declaration->diagnosis_key);
        $species = trim((string) $declaration->species_code);
        if ($key === '' || $species === '') {
            return false;
        }
        $minimum = $declaration->form === IsabvForm::CompanionTreatment ? 1 : 2;

        return IsabvCode::level($key) >= $minimum && IsabvCode::query()->ofKind(IsabvCode::KIND_DIAGNOSIS)
            ->where('species_code', self::diagnosisSpecies($species))->where('code', $key)->exists();
    }

    /**
     * @param  Collection<string, IsabvPreparation>  $preparations
     * @return list<string>
     */
    private function lineMissing(AntibioticDeclaration $declaration, AntibioticDeclarationLine $line, Collection $preparations, ?int $number): array
    {
        $of = $number !== null ? ' (préparation '.$number.')' : '';
        $missing = [];
        $preparation = $preparations->get((string) $line->preparation_code);
        if ($preparation === null) {
            return ['la préparation de la liste de l’OSAV'.$of];
        }
        if ($line->given_quantity === null || $line->given_quantity < 0) {
            $missing[] = 'la quantité remise'.$of;
        }
        if ($preparation->has_critical_substance && ! $line->is_critical_confirmed) {
            $missing[] = 'la confirmation de la substance critique'.$of;
        }
        if (in_array($declaration->form, [IsabvForm::StockSupply, IsabvForm::ZooLab], true)) {
            return $missing;
        }
        $category = $declaration->form->frequencyCategory();
        if (! ($line->dose_1 > 0)) {
            $missing[] = 'la quantité par administration'.$of;
        }
        if (! $this->isFrequency($line->frequency_1, $category)) {
            $missing[] = 'la fréquence d’administration'.$of;
        }
        if (! ($line->days_1 >= 1)) {
            $missing[] = 'le nombre de jours de traitement'.$of;
        }
        $second = [$line->dose_2, $line->frequency_2, $line->days_2];
        if (array_filter($second, fn ($value) => $value !== null && $value !== '') !== []
            && (! ($line->dose_2 > 0) || ! $this->isFrequency($line->frequency_2, $category) || ! ($line->days_2 >= 1))) {
            $missing[] = 'le 2e dosage complet'.$of;
        }
        if (! ($line->total_quantity > 0)) {
            $missing[] = 'la quantité totale nécessaire'.$of;
        }
        if ($line->route_code !== null && ! IsabvCode::query()->ofKind(IsabvCode::KIND_ROUTE)->where('code', $line->route_code)->exists()) {
            $missing[] = 'une voie d’administration de la liste de l’OSAV'.$of;
        }

        return $missing;
    }

    private function isFrequency(?string $code, string $category): bool
    {
        return $code !== null && $code !== '' && IsabvCode::query()->ofKind(IsabvCode::KIND_FREQUENCY)->where('code', $code)->where('category', $category)->exists();
    }

    /** @param Collection<int, AntibioticDeclarationLine> $lines */
    private function isLargeOralGroup(AntibioticDeclaration $declaration, Collection $lines): bool
    {
        if ($declaration->form !== IsabvForm::LivestockTreatment) {
            return false;
        }
        $isOral = $lines->contains(fn (AntibioticDeclarationLine $line) => in_array($line->route_code, ['po', 'pa'], true));

        return $isOral && (int) $declaration->animals_count > self::groupSize((string) $declaration->species_code);
    }

    /** OMédV, art. 3, al. 1, let. d : un groupe, c'est plus de 10 animaux, 20 porcs, 50 volailles ou lapins */
    public static function groupSize(string $species): int
    {
        return match (self::diagnosisSpecies($species)) {
            '2.2' => 20,
            '2.3', '2.8' => 50,
            default => 10,
        };
    }
}
