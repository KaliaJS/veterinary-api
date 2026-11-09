<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les animaux de rente (point c, 09.11.2026, choix de Jim : l'exploitation
 * et ses groupes) : un propriétaire se déclare exploitation (son n° BDTA
 * est déjà là, SI ABV) ; ses groupes d'animaux, avec leur nombre ; un
 * animal soigné seul est un dossier, avec sa marque auriculaire, dans son
 * groupe ; les visites d'exploitation, facturées à l'exploitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_farm')->default(false)->comment('Exploitation d’animaux de rente (onglet Exploitation)');
        });

        Schema::create('herds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name', 80)->comment('« Vaches laitières », « Veaux », « Brebis »');
            $table->string('species', 2)->comment('Espèce de rente (Espece)');
            $table->unsignedInteger('head_count')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'archived_at']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('herd_id')->nullable()->constrained('herds')->nullOnDelete();
            $table->string('ear_tag', 32)->nullable()->comment('Marque auriculaire (CH 1234.5678.9)');
            $table->index(['herd_id']);
        });

        Schema::create('farm_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('visited_on');
            $table->foreignId('vet_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vet_name')->nullable()->comment('Le vétérinaire, figé à la visite');
            $table->json('herd_ids')->nullable()->comment('Les groupes vus');
            $table->text('findings')->nullable()->comment('Constats');
            $table->foreignId('facture_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'visited_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_visits');
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('herd_id');
            $table->dropColumn('ear_tag');
        });
        Schema::dropIfExists('herds');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('is_farm'));
    }
};
