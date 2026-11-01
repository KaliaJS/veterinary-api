<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages entre collègues de la même organisation (décision de Jim,
     * 01.11.2026) : une conversation à deux, que seuls ces deux collègues
     * lisent. `read_at` est posé quand le destinataire ouvre la conversation.
     */
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignId('sender_employee_id')->constrained('employes')->cascadeOnDelete();
            $table->foreignId('recipient_employee_id')->constrained('employes')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sender_employee_id', 'recipient_employee_id', 'created_at'], 'staff_messages_thread_index');
            $table->index(['recipient_employee_id', 'read_at'], 'staff_messages_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
