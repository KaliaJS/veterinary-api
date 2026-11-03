<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Les actes d'avant la provenance (02.11.2026) reçoivent l'organisation du
 * dossier de l'animal : jusqu'ici, seule l'organisation du dossier y
 * écrivait. Les animaux sans organisation (ceux du propriétaire) restent
 * sans. Seuls les actes sans provenance sont touchés.
 */
final class BackfillRecordSources
{
    public const TABLES = ['pesees', 'documents', 'visites', 'vaccinations', 'releves_ophtalmologiques', 'releves_dentaires'];

    public function run(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNull('source_organisation_id')
                ->update(['source_organisation_id' => DB::table('patients')->select('organisation_id')->whereColumn('patients.id', $table.'.patient_id')]);
        }
    }
}
