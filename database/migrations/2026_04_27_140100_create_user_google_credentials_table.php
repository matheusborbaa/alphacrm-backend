<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I1 — Credenciais OAuth Google por usuário.
 *
 * Cada corretor pode conectar a própria conta Google.
 * Tokens guardados criptografados (cast 'encrypted' no model).
 *
 *   access_token       — válido por ~1h, refresh quando expira
 *   refresh_token      — long-lived (anos); usado pra obter novo access
 *   expires_at         — quando o access_token expira
 *   sync_token         — pra polling incremental do Calendar API (sync efficient)
 *   last_synced_at     — última vez que rodou sync bidirecional
 *   last_sync_error    — última falha (pra debug)
 *   email              — email do Google conectado (UI mostra "conectado como")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_google_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('email', 191)->nullable();

            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('scope')->nullable();
            $table->timestamp('expires_at')->nullable();


            $table->string('calendar_id', 191)->nullable()->default('primary');

            $table->string('sync_token', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_google_credentials');
    }
};
