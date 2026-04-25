<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.6 — Editar e apagar mensagens de chat.
 *
 * Dois campos novos:
 *  - edited_at: marca quando a msg foi editada pela última vez. NULL = nunca
 *    editada (frontend não mostra selo "(editada)" quando NULL). Atualizar
 *    body sem tocar nesse campo é proibido pelo controller; ou seja, todo
 *    UPDATE em body vem acompanhado de edited_at = now().
 *
 *  - deleted_at: SoftDeletes do Laravel. Msgs apagadas continuam no banco
 *    pra preservar a thread (frontend renderiza "Mensagem apagada" no
 *    lugar do body). Queries normais ignoram via global scope; rotas de
 *    listagem usam withTrashed() pra mostrar a placeholder.
 *
 * Por que NÃO juntar isso no migration original do chat_messages: porque
 * já tá em produção. Adicionar campos via migration nova é o caminho.
 *
 * Regras de negócio (aplicadas no ChatMessageController, não no schema):
 *  - Editar: só o autor, dentro de 15min, e ainda não lida (read_at IS NULL)
 *  - Apagar (autor): ainda não lida (read_at IS NULL)
 *  - Apagar (admin): sempre
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('read_at');
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('edited_at');
        });
    }
};
