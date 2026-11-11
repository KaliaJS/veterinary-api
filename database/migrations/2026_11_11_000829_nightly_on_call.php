<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La garde de chaque nuit (Jim, 11.11.2026) : la rotation reste
     * dérivée, seul un choix est une donnée, une nuit → un vétérinaire.
     * Les réattributions de week-end reprises le samedi et le dimanche.
     */
    public function up(): void
    {
        Schema::create('gardes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->date('jour');
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinique_id', 'jour']);
        });

        DB::table('gardes_weekend')->orderBy('id')->each(function (object $garde): void {
            $samedi = substr((string) $garde->samedi_le, 0, 10);
            foreach ([$samedi, date('Y-m-d', strtotime($samedi.' +1 day'))] as $jour) {
                DB::table('gardes')->insert([
                    'clinique_id' => $garde->clinique_id,
                    'jour' => $jour,
                    'employe_id' => $garde->employe_id,
                    'created_at' => $garde->created_at,
                    'updated_at' => $garde->updated_at,
                ]);
            }
        });

        Schema::drop('gardes_weekend');
    }

    public function down(): void
    {
        Schema::create('gardes_weekend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            $table->date('samedi_le');
            $table->foreignId('employe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinique_id', 'samedi_le']);
        });

        DB::table('gardes')->orderBy('id')->each(function (object $garde): void {
            $jour = substr((string) $garde->jour, 0, 10);
            if (date('N', (int) strtotime($jour)) === '6') {
                DB::table('gardes_weekend')->insert([
                    'clinique_id' => $garde->clinique_id,
                    'samedi_le' => $jour,
                    'employe_id' => $garde->employe_id,
                    'created_at' => $garde->created_at,
                    'updated_at' => $garde->updated_at,
                ]);
            }
        });

        Schema::drop('gardes');
    }
};
