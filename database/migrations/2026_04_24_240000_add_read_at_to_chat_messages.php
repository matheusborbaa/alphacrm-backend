<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.8c — Confirmação de leitura por mensagem.
 *
 * Antes a gente tinha só o cursor em `chat_conversation_reads`
 * (last_read_message_id + last_read_at) — sabe até onde o usuário leu,
 * mas todas as mensagens abaixo do cursor compartilhavam o mesmo
 * timestamp de leitura. Com esse campo cada msg carrega a hora EXATA
 * em que foi lida pela primeira vez pelo destinatário.
 *
 * Populado em ChatMessageController@markRead num UPDATE com
 * `WHERE read_at IS NULL` — idempotente por natureza, só escreve uma
 * vez por msg e preserva o timestamp original mesmo se o user reabrir
 * a conversa depois.
 *
 * Msgs antigas (pré-sprint) ficam com read_at NULL; o frontend faz
 * fallback pro cursor pra decidir ✓ vs ✓✓ nesses casos, sem exibir
 * o horário preciso (tooltip só diz "Lido").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('body');
            // Index pra consultas futuras tipo "mostre msgs ainda não
            // lidas" — é barato e ajuda quando o histórico crescer.
            $table->index(['conversation_id', 'read_at'], 'chat_messages_conv_read_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_conv_read_idx');
            $table->dropColumn('read_at');
        });
    }
};
