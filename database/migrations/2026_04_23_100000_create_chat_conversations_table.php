<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela chat_conversations.
 *
 * Para DM 1-a-1 usamos sempre um par (user_a_id, user_b_id) com ordenação
 * canônica: user_a_id < user_b_id. Isso permite unique index no par e faz
 * com que "conversa entre A e B" resolva sempre pro mesmo row, independe
 * de quem iniciou.
 *
 * `last_message_at` é a chave de ordenação da sidebar de conversas —
 * indexada pra lista de DMs do usuário ordenar barato por recentes.
 *
 * Futuro (Sprint 2/3): não mexemos em schema aqui. Anexos moram em
 * chat_message_attachments; last-read mora em chat_conversation_reads.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();

            // FK soft: usuário pode ser deletado (soft delete ou hard); preferimos
            // deixar a conversa visível pros outros participantes em vez de cascade.
            // IMPORTANTE: nullable() é obrigatório antes do nullOnDelete() — MySQL
            // recusa FK SET NULL em coluna NOT NULL (erro 1830).
            $table->foreignId('user_a_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_b_id')->nullable()->constrained('users')->nullOnDelete();

            // Cache da timestamp da última mensagem — evita subquery pesada na
            // listagem de conversas. Atualizado pelo controller quando envia msg.
            $table->timestamp('last_message_at')->nullable()->index();

            $table->timestamps();

            // Ordenação canônica garante unicidade do par independente de ordem
            // de criação. Ver ChatConversationController@store pra enforcement.
            $table->unique(['user_a_id', 'user_b_id'], 'chat_conversations_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
