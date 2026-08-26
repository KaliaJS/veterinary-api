<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `role_id` porte les permissions (rôle configurable par
     * organisation) ; l'ancien libellé libre `role` (« ASV · Accueil »)
     * devient `role_libelle`, pur affichage — le nom `role` est
     * réservé à la relation Eloquent.
     */
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->renameColumn('role', 'role_libelle');
        });

        Schema::table('employes', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role_libelle')
                ->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::table('employes', function (Blueprint $table) {
            $table->renameColumn('role_libelle', 'role');
        });
    }
};
