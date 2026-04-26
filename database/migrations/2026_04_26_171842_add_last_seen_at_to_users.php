<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint Auto-Offline — adiciona last_seen_at em users pra rastrear
 * última atividade real do corretor no sistema.
 *
 * Frontend envia heartbeat a cada 60s (visibility-aware: só com aba
 * em foco). Comando agendado MarkInactiveCorretoresOffline percorre
 * users com status_corretor='disponivel' AND last_seen_at < now - X
 * min e força offline — assim quem fecha o navegador deixando o status
 * "Disponível" não fica recebendo lead infinitamente.
 *
 * Setting que controla o threshold: corretor_auto_offline_minutes
 * (default 60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('status_corretor');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
