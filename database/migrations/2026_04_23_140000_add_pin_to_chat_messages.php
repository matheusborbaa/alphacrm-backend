<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.1 — Mensagens importantes (pin).
 *
 * Adiciona:
 *  - is_pinned: boolean (default false) — flag principal
 *  - pinned_at: timestamp nullable — quando foi pinada (ordenação da aba
 *    "Importantes" por mais recente primeiro)
 *  - pinned_by_user_id: fk users nullable — quem pinou (audit trail;
 *    participante A pode pinar msg do B, e vice-versa)
 *
 * Índice parcial-ish: (conversation_id, is_pinned) pra lookup rápido da
 * lista de pinadas de uma conversa. MySQL não suporta índice parcial
 * nativo, mas o índice composto já filtra bem porque a maioria das msgs
 * tem is_pinned=false (seletividade alta).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('body');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by_user_id')
                ->nullable()
                ->after('pinned_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['conversation_id', 'is_pinned'], 'chat_messages_conv_pinned_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_conv_pinned_idx');
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    }
};
