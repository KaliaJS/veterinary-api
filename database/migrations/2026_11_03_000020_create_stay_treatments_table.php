<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation, la ronde (03.11.2026) : les traitements en cours d'un
 * séjour. Un traitement donne ses heures de prise (minutes locales du jour) ;
 * chaque feuille du jour qui s'ouvre en tire ses administrations
 * (sejour_soins.treatment_id), et les jours à venir les montrent prévues
 * sans rien écrire. Arrêté, il ne prévoit plus rien ; jamais supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_treatments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sejour_id')->constrained('sejours')->cascadeOnDelete();
            $table->string('label')->comment('Nom du médicament tel que prescrit');
            $table->foreignId('product_id')->nullable()->comment('Produit du stock, sorti à chaque prise donnée')->constrained('produits')->restrictOnDelete();
            $table->string('dose')->nullable()->comment('« 1 mg/kg IV »');
            $table->json('times')->comment('Heures de prise, minutes locales du jour (0 à 1439)');
            $table->dateTime('starts_at')->comment('Rien n’est prévu avant');
            $table->dateTime('ends_at')->nullable()->comment('Arrêt du traitement : rien n’est prévu après');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable()->comment('Nom du prescripteur au moment de la prescription');
            $table->string('stopped_by_name')->nullable();
            $table->timestamps();

            $table->index(['sejour_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_treatments');
    }
};
