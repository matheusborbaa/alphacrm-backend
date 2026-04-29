<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


// Centraliza tudo de agendamento/visita numa única tarefa task_kind=agendamento.
// Adiciona campos pra observações de visita realizada + ficha + linkagem de reagendamento.
// Cria tabela de participantes (gerente, outro corretor, parceiro) que recebem notif e calendar.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {

            $table->text('visit_observations')->nullable()->after('cancellation_reason');
            $table->string('visit_doc_path', 500)->nullable()->after('visit_observations');


            $table->unsignedBigInteger('previous_appointment_id')->nullable()->after('visit_doc_path');
            $table->foreign('previous_appointment_id', 'fk_appt_previous')
                  ->references('id')->on('appointments')
                  ->nullOnDelete();
            $table->index('previous_appointment_id', 'idx_appt_previous');
        });


        Schema::create('appointment_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 30)->nullable();

            $table->string('external_event_id', 255)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->foreign('appointment_id', 'fk_apptpart_appt')
                  ->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('user_id', 'fk_apptpart_user')
                  ->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['appointment_id', 'user_id'], 'uq_apptpart_appt_user');
            $table->index('user_id', 'idx_apptpart_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_participants');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign('fk_appt_previous');
            $table->dropIndex('idx_appt_previous');
            $table->dropColumn(['visit_observations', 'visit_doc_path', 'previous_appointment_id']);
        });
    }
};
