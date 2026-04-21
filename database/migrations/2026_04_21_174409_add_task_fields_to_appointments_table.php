<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende a tabela `appointments` com os campos necessários pra
 * representar TAREFAS / FOLLOW-UPS (type='task').
 *
 * Decisão de design:
 *   - starts_at/ends_at continuam sendo o "horário" de eventos de
 *     agenda (visita, reunião, ligação marcada).
 *   - due_at é o PRAZO da tarefa. Uma tarefa pode ter só due_at ou só
 *     starts_at (se virar um evento agendado).
 *   - completed_at / completed_by guardam quando e quem marcou como
 *     feita (útil pros relatórios de produtividade da Fase C).
 *   - priority e reminder_at são comodidades: ordenação + disparo de
 *     notificação antecipada via job da Fase B.
 *
 * Todos os campos são nullable pra não quebrar registros de
 * appointments existentes.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {

            // Prazo da tarefa (distinto de starts_at, que é "começa às").
            $table->dateTime('due_at')
                  ->nullable()
                  ->after('ends_at');

            // Quando foi concluída (null = ainda aberta).
            $table->dateTime('completed_at')
                  ->nullable()
                  ->after('due_at');

            // Quem marcou como concluída. Sem constrained() pra manter
            // compatibilidade com o 'created_by' legado (que também não
            // tem FK); se o user for deletado, guardamos o ID histórico.
            $table->unsignedBigInteger('completed_by')
                  ->nullable()
                  ->after('completed_at');

            // low | medium | high. String em vez de enum pra facilitar
            // evolução (ex: adicionar 'urgent' depois sem migration).
            $table->string('priority', 10)
                  ->nullable()
                  ->after('completed_by');

            // Quando disparar notificação antecipada (usado pela Fase B3).
            $table->dateTime('reminder_at')
                  ->nullable()
                  ->after('priority');

            // Índices pras queries mais quentes: listar tarefas do user
            // por prazo, separar atrasadas de futuras.
            $table->index(['user_id', 'due_at'], 'appointments_user_due_idx');
            $table->index(['lead_id', 'due_at'], 'appointments_lead_due_idx');
            $table->index('completed_at', 'appointments_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_user_due_idx');
            $table->dropIndex('appointments_lead_due_idx');
            $table->dropIndex('appointments_completed_idx');

            $table->dropColumn([
                'due_at',
                'completed_at',
                'completed_by',
                'priority',
                'reminder_at',
            ]);
        });
    }
};
