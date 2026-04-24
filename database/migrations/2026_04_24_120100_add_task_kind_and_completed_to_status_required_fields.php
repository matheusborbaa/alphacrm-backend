<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.7b — regra de tarefa com subtipo e flag "concluída".
 *
 * Depende da migration 2026_04_24_110000 (require_task) e da
 * 2026_04_24_120000 (task_kind em appointments).
 *
 *   require_task_kind      : null = qualquer tarefa serve,
 *                            ou 'ligacao'|'visita'|'anotacao'|'generica'
 *   require_task_completed : se true, a tarefa precisa ter completed_at
 *                            preenchido (concluída de fato, não só aberta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->string('require_task_kind', 40)
                ->nullable()
                ->after('require_task');

            $table->boolean('require_task_completed')
                ->default(false)
                ->after('require_task_kind');
        });
    }

    public function down(): void
    {
        Schema::table('status_required_fields', function (Blueprint $table) {
            $table->dropColumn(['require_task_kind', 'require_task_completed']);
        });
    }
};
