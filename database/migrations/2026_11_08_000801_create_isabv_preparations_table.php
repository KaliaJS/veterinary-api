<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : la liste des préparations de l'OSAV
 * (`/psw/getMasterData/preparation`). Seules celles marquées « transfert SI
 * ABV » se déclarent (le serveur refuse une prescription sans au moins une
 * d'elles) ; le code est le n° d'autorisation Swissmedic et la séquence
 * (« 20258-01 »), ou un code de l'OSAV pour les formules magistrales et les
 * importations (« S00108 », « F00008 »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isabv_preparations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name_fr');
            $table->string('name_de')->nullable();
            $table->string('atc_code', 12)->nullable();
            $table->string('unit_code', 12)->comment('L’unité des quantités déclarées (Stück, ml, mg, g…)');
            $table->string('unit_label_fr', 24);
            $table->string('category_code', 12)->nullable()->comment('Inj, Tab, Oral, IMM-LC…');
            $table->string('category_label_fr', 60)->nullable();
            $table->string('route_code', 4)->nullable()->comment('Voie d’administration de la préparation, si l’OSAV la donne');
            $table->string('preparation_type', 24)->nullable()->comment('SWISS | HUMAN | FOREIGN | FORMULA_MAGISTRALIS');
            $table->boolean('must_transfer')->default(false)->comment('« transferISABV » : à déclarer');
            $table->boolean('has_critical_substance')->default(false)->comment('Substance active critique : le vétérinaire confirme la prescrire exprès');
            $table->timestamps();

            $table->index('must_transfer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isabv_preparations');
    }
};
