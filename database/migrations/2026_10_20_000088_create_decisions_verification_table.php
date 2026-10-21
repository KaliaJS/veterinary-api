<?php

use App\Enums\DecisionVerification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historique de la vérification manuelle d'un vétérinaire : sa
     * demande, puis chaque décision d'un modérateur (vérifié, refusé,
     * badge retiré), avec qui, quand et pourquoi. Le statut courant est
     * sur le compte (`users.verification_veterinaire`).
     */
    public function up(): void
    {
        Schema::create('decisions_verification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('decision', array_column(DecisionVerification::cases(), 'value'));
            $table->text('note')->nullable();
            $table->foreignId('moderateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_verification');
    }
};
