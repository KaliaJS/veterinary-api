<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les messages écrits avant le 27.10.2026 n'ont pas de fiche client
 * (vérification du 28.10.2026) : un compte détaché ou effacé laissait un
 * fil sans nom, qui ne s'ouvrait plus. On leur pose la fiche du compte
 * dans l'organisation de la clinique, comme à l'envoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('messages_clientele')
            ->join('cliniques', 'cliniques.id', '=', 'messages_clientele.clinique_id')
            ->join('societes', 'societes.id', '=', 'cliniques.societe_id')
            ->whereNull('messages_clientele.client_id')->whereNotNull('messages_clientele.user_id')
            ->select('messages_clientele.id', 'messages_clientele.user_id', 'societes.organisation_id')
            // Par identifiant, pas par décalage : chaque page remplie sort du filtre
            ->chunkById(500, function ($messages): void {
                foreach ($messages as $message) {
                    $fiche = DB::table('clients')->where('user_id', $message->user_id)->where('organisation_id', $message->organisation_id)->orderBy('id')->value('id');
                    if ($fiche !== null) {
                        DB::table('messages_clientele')->where('id', $message->id)->update(['client_id' => $fiche]);
                    }
                }
            }, 'messages_clientele.id', 'id');
    }

    public function down(): void
    {
        // Rien à défaire : la fiche posée est celle qu'aurait posée l'envoi
    }
};
