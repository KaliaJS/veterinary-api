<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radios annotées (décision de Jim, 28.10.2026) : l'original DICOM gardé
 * tel quel sur le disque privé, l'espèce du jour de la radio, région et
 * incidence en listes fixes validées par un humain ; les annotations
 * dessinées et les confirmations à part, jamais dans le DICOM ; l'accord
 * daté de l'organisation pour l'entraînement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliches_dicom', function (Blueprint $table): void {
            $table->unsignedBigInteger('taille_octets')->nullable()->after('chemin')->comment('Taille du fichier original');
            $table->char('empreinte_sha256', 64)->nullable()->after('taille_octets')->comment('Empreinte de l’original : il n’est jamais modifié');
            $table->string('syntaxe_transfert', 64)->nullable()->after('empreinte_sha256');
            $table->string('modalite_dicom', 16)->nullable()->after('syntaxe_transfert');
            $table->unsignedInteger('lignes')->nullable()->after('modalite_dicom');
            $table->unsignedInteger('colonnes')->nullable()->after('lignes');
            $table->unsignedInteger('images')->default(1)->after('colonnes')->comment('Images (trames) du fichier');
            $table->string('espece', 8)->nullable()->after('images')->comment('Espèce de l’animal le jour de la radio');
            $table->string('race')->nullable()->after('espece');
            $table->decimal('poids_kg', 6, 3)->nullable()->after('race')->comment('Dernier poids connu le jour de la radio');
            $table->string('region', 40)->nullable()->after('poids_kg')->comment('Liste fixe : config/imagerie.php');
            $table->string('incidence', 40)->nullable()->after('region')->comment('Liste fixe : config/imagerie.php');
            $table->json('reperes_dicom')->nullable()->after('incidence')->comment('BodyPartExamined, ViewPosition, Laterality bruts du fichier');
            $table->foreignId('reperes_valides_par')->nullable()->after('reperes_dicom')->constrained('users')->nullOnDelete();
            $table->dateTime('reperes_valides_le')->nullable()->after('reperes_valides_par');
            $table->foreignId('envoye_par')->nullable()->after('reperes_valides_le')->constrained('users')->nullOnDelete();
        });

        Schema::create('annotations_imagerie', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliche_dicom_id')->constrained('cliches_dicom')->restrictOnDelete();
            $table->string('type', 20)->comment('rectangle, ellipse, point, fleche, longueur, angle, contour');
            $table->json('points')->comment('[[x, y], …] en pixels de l’image d’origine (colonne, ligne)');
            $table->unsignedInteger('image')->default(0)->comment('Image (trame) du fichier, à partir de 0');
            $table->string('etiquette', 60)->comment('Code de la liste fixe');
            $table->string('certitude', 10)->comment('sur, suspect');
            $table->text('note')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version_schema')->default(1);
            $table->timestamps();

            $table->index(['cliche_dicom_id', 'etiquette']);
        });

        Schema::create('confirmations_imagerie', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('examen_imagerie_id')->constrained('examens_imagerie')->restrictOnDelete();
            $table->string('etiquette', 60)->comment('Code de la liste fixe');
            $table->string('resultat', 10)->comment('confirme, infirme');
            $table->string('source', 20)->comment('chirurgie, histologie, cytologie, echographie, scanner, irm, evolution, necropsie, autre');
            $table->date('constate_le');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['examen_imagerie_id', 'etiquette']);
        });

        Schema::table('organisations', function (Blueprint $table): void {
            $table->dateTime('entrainement_consenti_le')->nullable()->comment('Accord pour entraîner des modèles sur ses images anonymisées ; vide : pas d’accord');
            $table->foreignId('entrainement_consenti_par')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entrainement_consenti_par');
            $table->dropColumn('entrainement_consenti_le');
        });
        Schema::dropIfExists('confirmations_imagerie');
        Schema::dropIfExists('annotations_imagerie');
        Schema::table('cliches_dicom', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('envoye_par');
            $table->dropConstrainedForeignId('reperes_valides_par');
            $table->dropColumn(['taille_octets', 'empreinte_sha256', 'syntaxe_transfert', 'modalite_dicom', 'lignes', 'colonnes', 'images', 'espece', 'race', 'poids_kg', 'region', 'incidence', 'reperes_dicom', 'reperes_valides_le']);
        });
    }
};
