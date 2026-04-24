<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.0a — Sessões múltiplas controladas + reauth por senha.
 *
 * Adiciona metadados em personal_access_tokens pra suportar:
 *   - Limite N de sessões simultâneas por user (max_concurrent_sessions)
 *   - Tela "Meus dispositivos" com UA/IP/label do dispositivo
 *   - Reauth periódico por senha (password_confirm_idle_minutes):
 *       se (now - last_confirmed_password_at) > threshold, o middleware
 *       EnsureFreshAuthentication devolve 423 e o frontend pede a senha
 *       sem invalidar o token nem perder o estado da página.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // IPv4/IPv6 cabe em VARCHAR(45).
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->string('device_label', 120)->nullable()->after('user_agent');

            // Timestamp do último momento em que o user provou quem é
            // digitando a senha (login OU confirm-password). É o que o
            // middleware EnsureFreshAuthentication usa pra decidir se
            // pede senha de novo.
            $table->timestamp('last_confirmed_password_at')->nullable()->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'ip_address',
                'user_agent',
                'device_label',
                'last_confirmed_password_at',
            ]);
        });
    }
};
