<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ABV (08.11.2026) : comment une clinique déclare ses antibiotiques.
 * Variante 1 : par Veterinary, avec le token d'interface que l'OSAV
 * envoie par e-mail à l'inscription de son IDE et de son complément
 * (directives techniques, ch. 12.1.3) ; variante 2 : elle saisit tout dans l'application web, et
 * Veterinary n'envoie rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isabv_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinique_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('variant')->default(2)->comment('1 : par Veterinary ; 2 : par l’application web de l’OSAV');
            $table->string('appendix', 100)->nullable()->comment('Complément de l’IDE, tel qu’enregistré à l’OSAV');
            $table->text('token')->nullable()->comment('Token SI ABV, chiffré, jamais servi');
            $table->timestamp('verified_at')->nullable()->comment('Le serveur de l’OSAV a accepté l’IDE, le complément et le token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isabv_connections');
    }
};
