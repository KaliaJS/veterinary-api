<?php

use App\Models\Organisation;
use App\Services\PanelsLaboParDefaut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill du référentiel de panels labo pour les organisations
     * nées avant le domaine laboratoire — les nouvelles le reçoivent à
     * la création (Organisation::booted). Idempotent (firstOrCreate).
     */
    public function up(): void
    {
        foreach (Organisation::query()->get() as $organisation) {
            PanelsLaboParDefaut::creer($organisation);
        }
    }

    public function down(): void
    {
        DB::table('panels_labo')->whereIn('code', array_keys(PanelsLaboParDefaut::PANELS))->delete();
    }
};
