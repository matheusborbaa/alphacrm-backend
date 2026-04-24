<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.4 — Reply/citação de mensagens no chat.
 *
 * `reply_to_id` aponta pra ID de outra msg na MESMA conversa (o controller
 * valida isso no store — FK sozinho não garante, só que a msg existe).
 *
 * ON DELETE SET NULL — se a msg-pai for apagada no futuro, a resposta
 * continua visível e vira um "reply órfão" (o frontend mostra "Mensagem
 * indisponível" no bloco citado). Isso preserva histórico sem regras
 * complicadas de cascata.
 *
 * Index pra futuras features tipo "mostrar todas as respostas a uma msg"
 * ou contador de threads; hoje o frontend só usa pra renderizar o bloco
 * citado acima do body.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('body')
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->index('reply_to_id', 'chat_messages_reply_to_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_reply_to_idx');
            $table->dropForeign(['reply_to_id']);
            $table->dropColumn('reply_to_id');
        });
    }
};
