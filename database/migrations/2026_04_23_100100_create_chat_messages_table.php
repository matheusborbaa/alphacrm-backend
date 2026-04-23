<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens do chat. Um row por mensagem enviada.
 *
 * - body é TEXT pra aguentar parágrafo longo (LGPD: atenção com PII em msg,
 *   Sprint futuro pode adicionar redação / retenção).
 * - sender_id vira null se o usuário for deletado, mas a mensagem fica
 *   (histórico auditável). Renderiza "usuário removido" no frontend.
 * - conversation_id cascade: se a conversa for deletada (rare event —
 *   idealmente soft delete a nível de app), mensagens vão juntas.
 *
 * Índices:
 *  - (conversation_id, created_at) — principal acesso: listar mensagens
 *    da conversa em ordem cronológica.
 *  - sender_id — pra estatísticas futuras.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();

            $table->foreignId('sender_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
