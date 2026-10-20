<?php

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name');
            $table->enum('species', array_column(Espece::cases(), 'value'))->nullable();
            $table->string('owner_name');
            $table->string('reason');
            $table->string('souhait')->comment('Souhait horaire libre (« Aujourd’hui après 16:00 »)');
            $table->enum('mode', array_column(ModeDemandeRendezVous::cases(), 'value'));
            $table->unsignedSmallInteger('duree_minutes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_rendez_vous');
    }
};
