<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Les appareils à prévenir par Apple (APNs), décision de Jim du 28.10.2026 :
 * la montre donne son jeton ; l'API la réveille quand la journée change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('jeton', 200)->unique()->comment('Jeton de l’appareil chez Apple');
            $table->string('environnement', 12)->comment('sandbox (Xcode) | production (TestFlight, App Store)');
            $table->string('plateforme', 12)->comment('watchos | ios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils_notifications');
    }
};
