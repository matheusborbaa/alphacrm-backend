<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna `starts_at` nullable em `appointments`.
 *
 * Motivo: TAREFAS (type='task') não têm horário de início — elas têm
 * PRAZO (`due_at`). Antes dessa mudança, o NOT NULL de `starts_at`
 * impedia a criação de tarefas pelo /api/tasks.
 *
 * Impacto:
 *   - Registros EXISTENTES (visitas/reuniões/etc) continuam intactos
 *     — todos já têm starts_at preenchido.
 *   - AppointmentController@store continua exigindo starts_at na
 *     validação (regra: required|date), então eventos de agenda
 *     seguem com o comportamento antigo.
 *   - TaskController@store nem passa starts_at, o que agora é válido
 *     no nível do banco.
 *
 * Nota: altera coluna in-place usando doctrine/dbal. Se o projeto não
 * tiver doctrine/dbal instalado, rodar:
 *     composer require doctrine/dbal
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // CUIDADO: se houver appointments criados como tarefa (starts_at=null)
        // esse rollback vai falhar. Zerar manualmente antes de reverter.
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable(false)->change();
        });
    }
};
