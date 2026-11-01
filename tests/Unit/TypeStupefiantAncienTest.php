<?php

namespace Tests\Unit;

use App\Support\TypeStupefiantAncien;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les anciennes lignes du registre des stupéfiants reprennent le bon type
 * (vérification du 01.11.2026 : « Correction de volume » et « Seringue
 * involontairement vidée » devenaient des vols, « Retour au fournisseur »
 * et « Inventaire : écart » des corrections).
 */
class TypeStupefiantAncienTest extends TestCase
{
    /** @return array<string, array{0: float, 1: bool, 2: string, 3: string}> */
    public static function lignes(): array
    {
        return [
            'administration' => [-1.0, true, 'Prémédication', 'administration'],
            'report d’ouverture' => [12.0, false, 'Report d’ouverture du registre', 'inventaire'],
            'inventaire, écart' => [-0.5, false, 'Inventaire : écart', 'inventaire'],
            'retour au fournisseur' => [-10.0, false, 'Retour au fournisseur', 'retour'],
            'réception' => [20.0, false, 'Réception grossiste', 'reception'],
            'livraison du lot' => [20.0, false, 'Livraison, lot A12', 'reception'],
            'vol' => [-5.0, false, 'Vol constaté', 'vol'],
            'volé' => [-5.0, false, 'Flacon volé dans la voiture', 'vol'],
            'correction de volume' => [-0.2, false, 'Correction de volume', 'correction'],
            'seringue involontairement vidée' => [-1.0, false, 'Seringue involontairement vidée', 'perte'],
            'flacon cassé' => [-3.0, false, 'Flacon cassé', 'perte'],
            'périmé détruit' => [-2.0, false, 'Périmé, détruit', 'elimination'],
            'entrée sans motif' => [5.0, false, '', 'reception'],
            'sortie sans motif' => [-1.0, false, '', 'correction'],
        ];
    }

    #[DataProvider('lignes')]
    public function test_le_type_d_une_ancienne_ligne(float $quantite, bool $versUnAnimal, string $motif, string $attendu): void
    {
        $this->assertSame($attendu, TypeStupefiantAncien::type($quantite, $versUnAnimal, $motif));
    }
}
