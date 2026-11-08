<?php

namespace App\Services\Isabv;

use App\Enums\IsabvForm;
use App\Models\AntibioticDeclaration;
use App\Models\Clinique;
use DOMDocument;
use DOMElement;
use LibXMLError;

/**
 * Le XML d'une déclaration, selon le XSD de son formulaire
 * (resources/isabv/xsd, OSAV, 31.03.2026), dans l'ordre des éléments du
 * schéma (08.11.2026). Le champ de référence du logiciel porte le numéro de
 * la pièce d'origine (ordonnance), pour la retrouver.
 */
class DeclarationXml
{
    public function build(AntibioticDeclaration $declaration, Clinique $clinique, ?string $appendix, ?string $reference = null): string
    {
        $form = $declaration->form;
        $document = new DOMDocument('1.0', 'utf-8');
        $root = $document->createElementNS($form->namespace(), $form->value);
        $document->appendChild($root);
        $add = function (DOMElement $parent, string $name, mixed $value) use ($document, $form): void {
            if ($value === null || $value === '') {
                return;
            }
            $element = $document->createElementNS($form->namespace(), $name);
            $element->appendChild($document->createTextNode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
            $parent->appendChild($element);
        };

        $add($root, 'dateOfVisit', $declaration->visit_on->format('Y-m-d').'T00:00:00');
        $add($root, 'recipeNumber', $declaration->recipe_number);
        $add($root, 'veterinaryPracticeUID', self::uid($clinique));
        $add($root, 'appendix', $appendix !== null && trim($appendix) !== '' ? trim($appendix) : null);
        $add($root, 'veterinaryPracticeAddress', self::practiceAddress($clinique));
        $add($root, 'species', $declaration->species_code);
        $lines = $declaration->relationLoaded('lines') ? $declaration->lines : $declaration->lines()->get();

        if ($form === IsabvForm::StockSupply || $form === IsabvForm::ZooLab) {
            $line = $lines->first();
            if ($form === IsabvForm::StockSupply) {
                $add($root, 'tvd', $declaration->holding_number);
            }
            $add($root, 'address', $declaration->holding_address);
            $add($root, 'preparationID', $line?->preparation_code);
            $add($root, 'givenAmount', self::number($line?->given_quantity));
            $add($root, 'additionalInstructions', $declaration->additional_instructions);
            $add($root, 'reference', $reference !== null ? mb_substr($reference, 0, 50) : null);
            $add($root, 'antibiogramPerformed', $declaration->is_antibiogram_done ? true : null);
            $add($root, 'criticalSubstanceConfirmed', $line?->is_critical_confirmed ? true : null);
            if ($form === IsabvForm::StockSupply) {
                $add($root, 'diagnosisKey', $declaration->diagnosis_key);
                // Au moins deux des trois (choix du XSD)
                $add($root, 'treatingAnimalsNumber', $declaration->animals_count > 0 ? $declaration->animals_count : null);
                $add($root, 'treatmentDays', $declaration->av_treatment_days > 0 ? $declaration->av_treatment_days : null);
                $add($root, 'animalDosis', $declaration->av_daily_dose > 0 ? self::number($declaration->av_daily_dose) : null);
            }

            return (string) $document->saveXML();
        }

        $add($root, 'identification', $declaration->identification);
        if ($form === IsabvForm::LivestockTreatment) {
            $add($root, 'tvd', $declaration->holding_number);
            $add($root, 'address', $declaration->holding_address);
        } else {
            $add($root, 'postcode', $declaration->postcode);
        }
        $add($root, 'treatingAnimalsNumber', $declaration->animals_count > 0 ? $declaration->animals_count : null);
        $add($root, 'animalWeight', $declaration->weight_kg > 0 ? self::number($declaration->weight_kg, 6) : null);
        if ($form === IsabvForm::LivestockTreatment) {
            $add($root, 'animalsTotal', $declaration->animals_total > 0 ? $declaration->animals_total : null);
        }
        $add($root, 'diagnosisKey', $declaration->diagnosis_key);
        $add($root, 'diagnosisDescription', $declaration->diagnosis_description);
        $preparations = $document->createElementNS($form->namespace(), 'preparations');
        $root->appendChild($preparations);
        foreach ($lines as $line) {
            $preparation = $document->createElementNS($form->namespace(), 'preparation');
            $preparations->appendChild($preparation);
            $add($preparation, 'preparationID', $line->preparation_code);
            $add($preparation, 'applicationType', $line->route_code);
            foreach ([['firstDosis', $line->dose_1, $line->days_1, $line->frequency_1], ['secondDosis', $line->dose_2, $line->days_2, $line->frequency_2]] as [$name, $dose, $days, $frequency]) {
                if ($dose === null || $days === null || $frequency === null) {
                    continue;
                }
                $dosis = $document->createElementNS($form->namespace(), $name);
                $preparation->appendChild($dosis);
                $add($dosis, 'animalDosis', self::number($dose));
                $add($dosis, 'treatmentDays', $days);
                $add($dosis, 'servingMode', $frequency);
            }
            $add($preparation, 'totalDosis', self::number($line->total_quantity));
            $add($preparation, 'givenAmount', self::number($line->given_quantity));
            $add($preparation, 'treatmentStart', $line->starts_on?->format('Y-m-d'));
            $add($preparation, 'treatmentDays', (int) $line->days_1 + (int) $line->days_2);
            $add($preparation, 'treatmentEnd', $line->ends_on?->format('Y-m-d'));
            $add($preparation, 'criticalSubstanceConfirmed', $line->is_critical_confirmed ? true : null);
        }
        $add($root, 'additionalInstructions', $declaration->additional_instructions);
        $add($root, 'reference', $reference !== null ? mb_substr($reference, 0, 50) : null);
        $add($root, 'antibiogramPerformed', $declaration->is_antibiogram_done ? true : null);

        return (string) $document->saveXML();
    }

    /**
     * Les écarts au XSD officiel de son formulaire, vide si le XML est conforme.
     *
     * @return list<string>
     */
    public static function schemaErrors(string $xml, IsabvForm $form): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument;
        $isValid = $document->loadXML($xml, LIBXML_NONET) && $document->schemaValidate($form->xsdPath());
        $errors = array_map(fn (LibXMLError $error) => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $isValid ? [] : array_values(array_unique($errors ?: ['XML illisible']));
    }

    /** L'IDE de la clinique (« CHE-123.456.789 »), celui de sa société */
    public static function uid(Clinique $clinique): ?string
    {
        $ide = trim((string) $clinique->societe?->ide);

        return $ide !== '' ? $ide : null;
    }

    public static function practiceAddress(Clinique $clinique): string
    {
        return implode(', ', array_filter([trim((string) $clinique->nom), trim((string) $clinique->rue), trim(trim((string) $clinique->npa).' '.trim((string) $clinique->ville))]));
    }

    /** Un nombre sans zéros inutiles (« 2.5 », « 14 ») */
    private static function number(?float $value, int $decimals = 4): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
