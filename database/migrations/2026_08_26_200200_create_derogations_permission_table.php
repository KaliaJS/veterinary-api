<?php

use App\Enums\DomainePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dérogations par employé : accordent (`est_accordee` = vrai) ou
     * retirent (faux) un domaine indépendamment du rôle.
     */
    public function up(): void
    {
        Schema::create('derogations_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->enum('domaine', DomainePermission::valeurs());
            $table->boolean('est_accordee');
            $table->timestamps();

            $table->unique(['employe_id', 'domaine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('derogations_permission');
    }
};
