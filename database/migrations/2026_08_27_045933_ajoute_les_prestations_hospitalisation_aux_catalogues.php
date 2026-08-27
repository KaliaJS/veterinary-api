<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Les tarifs de séjour d'hospitalisation (forfaits journaliers par
     * état clinique, perfusion, soin) rejoignent le catalogue
     * `prestations` : deux catalogues divergents ont déjà produit deux
     * prix pour les mêmes actes, les constantes PHP de FactureSejour
     * recréaient le cas. Backfill idempotent des organisations
     * existantes — les nouvelles reçoivent ces actes via
     * PrestationsParDefaut à leur création.
     */
    private const array PRESTATIONS = [
        'hosp_stable' => ['Hospitalisation — journée, surveillance simple', 65],
        'hosp_surv' => ['Hospitalisation — journée, surveillance rapprochée', 95],
        'hosp_critique' => ['Hospitalisation — journée, soins intensifs', 140],
        'hosp_perfusion' => ['Perfusion continue — journée d\'hospitalisation', 38],
        'hosp_soin' => ['Soin infirmier — hospitalisation', 15],
    ];

    public function up(): void
    {
        foreach (DB::table('organisations')->pluck('id') as $organisationId) {
            foreach (self::PRESTATIONS as $code => [$nom, $prix]) {
                $existe = DB::table('prestations')
                    ->where('organisation_id', $organisationId)
                    ->where('code', $code)
                    ->exists();

                if (! $existe) {
                    DB::table('prestations')->insert([
                        'organisation_id' => $organisationId,
                        'code' => $code,
                        'nom' => $nom,
                        'prix' => $prix,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('prestations')->whereIn('code', array_keys(self::PRESTATIONS))->delete();
    }
};
