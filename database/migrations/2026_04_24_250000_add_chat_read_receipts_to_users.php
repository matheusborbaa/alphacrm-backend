<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.8d — Preferência individual de confirmação de leitura.
 *
 * Padrão WhatsApp: cada usuário decide se expõe aos outros quando leu
 * as mensagens deles. A regra é RECÍPROCA — se EU desligo, eu também
 * não vejo ✓✓ nas minhas mensagens. Aplicado em:
 *
 *   - ChatMessageController@markRead → só grava read_at individual
 *     quando o leitor (me) tem a flag ativa
 *   - ChatMessageController@index   → só expõe read_at / peer_read
 *     quando os DOIS participantes têm a flag ativa
 *
 * Default = true pra não mudar o comportamento atual de quem já usa
 * o sistema.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('chat_read_receipts')->default(true)->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chat_read_receipts');
        });
    }
};
