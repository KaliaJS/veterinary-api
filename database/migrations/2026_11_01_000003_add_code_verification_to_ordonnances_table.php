<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Le code de vérification imprimé sur l'ordonnance (01.11.2026, demande
     * de Jim) : quatre chiffres, tapés avec le numéro de l'ordonnance sur
     * veterinary.app/ordo (ou par le code QR), pour que le pharmacien en
     * vérifie l'authenticité. Le couple numéro et code est unique (deux
     * organisations peuvent avoir le même numéro). Le code QR, lui, porte
     * un jeton long et secret (`jeton_verification`) : il ne se devine pas.
     * Les ordonnances déjà signées reçoivent les leurs.
     */
    public function up(): void
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            $table->string('code_verification', 4)->nullable()->after('numero');
            $table->string('jeton_verification', 32)->nullable()->unique()->after('code_verification');
            $table->unique(['numero', 'code_verification']);
        });

        $pris = [];
        DB::table('ordonnances')->whereNull('code_verification')->orderBy('id')->get(['id', 'numero'])->each(function (object $ordonnance) use (&$pris): void {
            do {
                $code = str_pad((string) random_int(0, 9_999), 4, '0', STR_PAD_LEFT);
            } while (isset($pris[$ordonnance->numero.'|'.$code]));
            $pris[$ordonnance->numero.'|'.$code] = true;
            DB::table('ordonnances')->where('id', $ordonnance->id)->update(['code_verification' => $code, 'jeton_verification' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            $table->dropUnique(['numero', 'code_verification']);
            $table->dropUnique(['jeton_verification']);
            $table->dropColumn(['code_verification', 'jeton_verification']);
        });
    }
};
