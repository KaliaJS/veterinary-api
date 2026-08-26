<?php

use App\Enums\Espece;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patients à rappeler dès qu'un créneau se libère (annulation,
     * no-show). `ligne_planning_id` null = n'importe quel vétérinaire.
     */
    public function up(): void
    {
        Schema::create('liste_attente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name');
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name');
            $table->string('tel')->nullable();
            $table->string('reason');
            $table->string('preference')->nullable()->comment('« Le matin de préférence »…');
            $table->foreignId('ligne_planning_id')->nullable()->constrained('lignes_planning')->nullOnDelete();
            $table->unsignedSmallInteger('duree_minutes');
            $table->dateTime('inscrit_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liste_attente');
    }
};
