<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages entre deux cliniques inscrites sur Veterinary (décision de
     * Jim, 01.11.2026) : un canal pour toute l'équipe de chacune, comme un
     * groupe. `author_employee_id` signe le message ; `read_at` est posé
     * quand quelqu'un de la clinique destinataire ouvre le canal.
     */
    public function up(): void
    {
        Schema::create('clinic_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_clinic_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('recipient_clinic_id')->constrained('cliniques')->cascadeOnDelete();
            $table->foreignId('author_employee_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sender_clinic_id', 'recipient_clinic_id', 'created_at'], 'clinic_messages_thread_index');
            $table->index(['recipient_clinic_id', 'read_at'], 'clinic_messages_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_messages');
    }
};
