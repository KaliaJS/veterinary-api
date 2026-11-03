<?php

use App\Support\Telephone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Créer un propriétaire (choix de Jim, 03.11.2026) : un mobile déjà connu
 * arrête la création. Le numéro de la fiche est gardé aussi au format
 * international (+41791234567), pour reconnaître « 079 123 45 67 »,
 * « +41 79 123 45 67 » et « 0041791234567 » comme le même numéro, dans
 * l'organisation seulement. Rempli par le modèle à chaque enregistrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('phone_e164', 16)->nullable()->after('tel')->comment('Le téléphone au format international, pour reconnaître un numéro déjà connu');
            $table->index(['organisation_id', 'phone_e164']);
        });

        DB::table('clients')->whereNotNull('tel')->orderBy('id')->chunkById(500, function ($clients): void {
            foreach ($clients as $client) {
                DB::table('clients')->where('id', $client->id)->update(['phone_e164' => Telephone::international($client->tel)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
