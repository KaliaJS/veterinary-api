<?php

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dates absolues (les seeds du front stockent des offsets relatifs
     * à aujourd'hui — divergence corrigée en base).
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->enum('type', array_column(TypeAbsence::cases(), 'value'));
            $table->date('debut_le');
            $table->date('fin_le');
            $table->enum('statut', array_column(StatutAbsence::cases(), 'value'))->default('att');
            $table->text('note')->nullable();
            $table->foreignId('valide_par_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'debut_le', 'fin_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
