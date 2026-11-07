<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les consentements déjà signés reprennent leur auteur (07.11.2026), par le
 * lien direct de l'intervention vers son document : qui l'a recueilli, sinon
 * qui a envoyé le lien signé à distance. Un document qu'aucune intervention
 * ne désigne reste sans auteur : rien ne le dit sans ambiguïté.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collector = DB::table('interventions')
            ->select('interventions.consentement_recueilli_par_id')
            ->whereColumn('interventions.consentement_document_id', 'documents.id')
            ->whereNotNull('interventions.consentement_recueilli_par_id')
            ->limit(1);
        $sender = DB::table('consent_requests')
            ->join('interventions', 'interventions.id', '=', 'consent_requests.intervention_id')
            ->select('consent_requests.sent_by_employee_id')
            ->whereColumn('interventions.consentement_document_id', 'documents.id')
            ->whereNotNull('consent_requests.signed_at')
            ->whereNotNull('consent_requests.sent_by_employee_id')
            ->limit(1);

        DB::table('documents')
            ->where('type', 'consentement')
            ->whereNull('employe_id')
            ->update(['employe_id' => DB::raw('coalesce(('.$collector->toSql().'), ('.$sender->toSql().'))')]);
    }

    public function down(): void
    {
        // Rien à défaire : l'auteur repris reste juste
    }
};
