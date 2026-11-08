<?php

namespace Tests\Concerns;

use App\Models\Clinique;
use App\Models\IsabvCode;
use App\Models\IsabvConnection;
use App\Models\IsabvPreparation;
use Illuminate\Support\Facades\Crypt;

/**
 * SI ABV dans les essais (08.11.2026) : un extrait des données de base de
 * l'OSAV (codes réels du 08.11.2026) et une clinique qui déclare par
 * Veterinary (variante 1, token accepté).
 */
trait PrepareLeSiAbv
{
    protected function donneesDeBaseSiAbv(): void
    {
        $species = [
            ['1', null, 'Animal de compagnie', false], ['1.5', '1', 'Chien', true], ['1.4', '1', 'Chat', true], ['1.1', '1', 'Équidé', true], ['1.11', '1', 'Autres', true],
            ['2', null, 'Animal de rente', false], ['2.1', '2', 'Bovin', false], ['2.1.5', '2.1', 'Vache laitière', false], ['2.2', '2', 'Porc', false], ['2.2.3', '2.2', 'Porc à l\'engrais', false],
            ['5', null, 'Animaux de laboratoire et de zoo', false], ['5.2', '5', 'Animal de zoo', false], ['5.2.1', '5.2', 'Mammifère', null],
        ];
        foreach ($species as [$code, $parent, $label, $consultations]) {
            IsabvCode::create(['kind' => 'species', 'code' => $code, 'parent_code' => $parent, 'label_fr' => $label, 'is_valid_for_consultations' => $consultations]);
        }
        foreach ([['1.5', '3', null, 'Maladies urogénitales'], ['1.5', '3.7', '3', 'Maladies urologiques'], ['1.5', '3.7.1', '3.7', 'Infection urinaire simple'],
            ['2.1', '2', null, 'Mamelle'], ['2.1', '2.1', '2', 'Mammite'], ['2.2', '5', null, 'Appareil digestif'], ['2.2', '5.1', '5', 'Diarrhée']] as [$sp, $code, $parent, $label]) {
            IsabvCode::create(['kind' => 'diagnosis', 'species_code' => $sp, 'code' => $code, 'parent_code' => $parent, 'label_fr' => $label]);
        }
        foreach ([['11', 'EH', 1.0, '1 administration / jour'], ['12', 'EH', 2.0, '2 administrations / jour'], ['20', 'EH', 0.5, '1 jour sur 2'],
            ['6', 'EN', 1.0, '1 administration / jour'], ['7', 'EN', 2.0, '2 administrations / jour']] as [$code, $category, $perDay, $label]) {
            IsabvCode::create(['kind' => 'frequency', 'code' => $code, 'category' => $category, 'per_day' => $perDay, 'label_fr' => $label]);
        }
        foreach ([['po', 'oral'], ['im', 'intramusculaire'], ['mm', 'intramammaire']] as [$code, $label]) {
            IsabvCode::create(['kind' => 'route', 'code' => $code, 'label_fr' => $label]);
        }
        foreach ([
            ['12345-01', 'Amoxicilline vétérinaire 250 mg, comprimés', 'Stück', 'pièces', 'po', true, false],
            ['22222-01', 'Enrofloxacine 50 mg, comprimés', 'Stück', 'pièces', 'po', true, true],
            ['33333-01', 'Pommade ophtalmique à la chlortétracycline', 'g', 'g', null, false, false],
            ['44444-02', 'Pénicilline intramammaire', 'Stück', 'pièces', 'mm', true, false],
        ] as [$code, $name, $unit, $unitLabel, $route, $transfer, $critical]) {
            IsabvPreparation::create(['code' => $code, 'name_fr' => $name, 'unit_code' => $unit, 'unit_label_fr' => $unitLabel, 'route_code' => $route, 'must_transfer' => $transfer, 'has_critical_substance' => $critical, 'preparation_type' => 'SWISS']);
        }
    }

    protected function cliniqueQuiDeclare(Clinique $clinique, string $token = 'feb3786f-c5d2-47cc-a8e0-e6d759548ff2'): IsabvConnection
    {
        $clinique->societe->update(['ide' => 'CHE-123.456.789']);
        $clinique->update(['nom' => 'Clinique des Deux Rives', 'rue' => 'Rue du Pont 4', 'npa' => '1700', 'ville' => 'Fribourg']);

        return IsabvConnection::withoutGlobalScopes()->create([
            'clinique_id' => $clinique->id,
            'variant' => IsabvConnection::VIA_VETERINARY,
            'token' => Crypt::encryptString($token),
            'verified_at' => now(),
        ]);
    }
}
