<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.7b — subtipo de tarefa.
 *
 * A coluna `type` em appointments tem valores macro ('task', 'visit',
 * 'call', 'meeting'). Dentro de type='task', o corretor agora escolhe
 * um SUBTIPO: ligacao, visita (como intenção de ação), anotacao, ou
 * generica. Isso permite que a regra de obrigatoriedade exija
 * especificamente "ligação concluída" em vez de só "tarefa qualquer".
 *
 * Null significa tarefas legadas criadas antes desta migration —
 * equivalem a 'generica' para fins de matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('task_kind', 40)
                ->nullable()
                ->after('type')
                ->comment('Subtipo da tarefa: ligacao|visita|anotacao|generica');

            $table->index('task_kind', 'appointments_task_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_task_kind_idx');
            $table->dropColumn('task_kind');
        });
    }
};
