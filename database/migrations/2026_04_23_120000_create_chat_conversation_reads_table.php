<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela chat_conversation_reads.
 *
 * Rastreia "até onde cada usuário leu cada conversa". Usada pra:
 *  - contar não-lidas por conversa (msg.id > last_read_message_id AND sender != user)
 *  - badge global consolidado no sidebar
 *  - futuramente: indicador visual "msg nova desde sua última visita"
 *
 * Por que ID e timestamp? O ID é a chave canônica (sequencial por conversa,
 * sempre crescente). O timestamp é redundante mas ajuda auditoria/debug.
 *
 * Unique (user_id, conversation_id): um registro por par — upsert sempre.
 * Cascade on delete da conversa: se conversa some, registros de leitura somem.
 * NullOnDelete do user: preserva o registro se user for removido (soft/hard),
 * embora na prática ele vire lixo; cleanup fica pra maintenance job futuro.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversation_reads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();

            // Maior id de mensagem que o usuário já viu nessa conversa.
            // Nullable no começo (antes do user abrir a conversa pela 1a vez).
            $table->unsignedBigInteger('last_read_message_id')->nullable();

            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'conversation_id'], 'chat_reads_user_conv_unique');

            // Index útil pra query global de unread por user.
            $table->index(['user_id'], 'chat_reads_user_idx');
        });

        // Backfill: conversas da Sprint 1 já têm mensagens. Sem isso, TODAS
        // contariam como não-lidas na primeira carga da feature. Marca cada
        // participante como "leu tudo até o último id existente" — zera o
        // contador ao deploy. Novas mensagens (pós-deploy) começam a contar
        // normalmente.
        //
        // SQL: pra cada conversa, inserir 1 registro por participante
        // (user_a e user_b) com last_read_message_id = max(id da msg na conv).
        $now = now();
        DB::table('chat_conversations')->orderBy('id')->chunk(500, function ($convs) use ($now) {
            $rows = [];
            foreach ($convs as $c) {
                $maxId = DB::table('chat_messages')
                    ->where('conversation_id', $c->id)
                    ->max('id');
                if (!$maxId) continue; // sem msgs, nada a marcar

                foreach ([$c->user_a_id, $c->user_b_id] as $userId) {
                    if (!$userId) continue; // user foi removido (nullOnDelete)
                    $rows[] = [
                        'user_id'              => $userId,
                        'conversation_id'      => $c->id,
                        'last_read_message_id' => $maxId,
                        'last_read_at'         => $now,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }
            }
            if (!empty($rows)) {
                // Upsert via insertOrIgnore — defensivo; unique index é o guardrail real.
                DB::table('chat_conversation_reads')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversation_reads');
    }
};
