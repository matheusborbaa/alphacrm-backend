<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix do bug: upload sozinho retornava SQLSTATE[23000] — "Column 'message_id'
 * cannot be null" — porque a migration original criou message_id como NOT NULL.
 *
 * O fluxo de anexos funciona em 2 passos:
 *   1. Frontend sobe o arquivo PRIMEIRO (POST /chat/attachments/upload). Nesse
 *      momento ainda NÃO existe uma ChatMessage — o anexo é um "draft"
 *      (message_id = NULL, type = 'upload', uploader_user_id = eu).
 *   2. Quando o user clica Enviar, o ChatMessageController@store cria a
 *      ChatMessage e adota os drafts setando message_id pela primeira vez.
 *
 * Ou seja: message_id PRECISA ser nullable pra suportar a janela entre upload
 * e envio. Esta migration corrige isso, removendo a FK antiga, marcando a
 * coluna como nullable e re-adicionando a FK com nullOnDelete (porque a partir
 * de agora faz sentido null — o anexo pode nunca ser "adotado" por uma msg).
 *
 * Precisa de doctrine/dbal pro ->change(). Se não estiver instalado usamos
 * ALTER TABLE cru (MySQL-only, mas é o DB de produção).
 */
return new class extends Migration {
    public function up(): void
    {
        // Drop FK antiga — precisa remover antes de alterar a coluna.
        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        // Altera pra nullable. Usa SQL cru pra não depender de doctrine/dbal.
        DB::statement('ALTER TABLE chat_message_attachments MODIFY message_id BIGINT UNSIGNED NULL');

        // Re-adiciona a FK com nullOnDelete (mais correto semanticamente: se a
        // msg some, zera o ponteiro — mas na prática cascadeOnDelete do
        // original seguia fazendo sentido. Mantemos cascade aqui também pra
        // não deixar órfão no banco).
        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->foreign('message_id')
                ->references('id')->on('chat_messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Rollback: volta pra NOT NULL. CUIDADO: se houver drafts
        // (message_id=NULL) no banco, esse ALTER falha. Se precisar rodar,
        // rodar antes: DELETE FROM chat_message_attachments WHERE message_id IS NULL;
        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        DB::statement('ALTER TABLE chat_message_attachments MODIFY message_id BIGINT UNSIGNED NOT NULL');

        Schema::table('chat_message_attachments', function (Blueprint $table) {
            $table->foreign('message_id')
                ->references('id')->on('chat_messages')
                ->cascadeOnDelete();
        });
    }
};
