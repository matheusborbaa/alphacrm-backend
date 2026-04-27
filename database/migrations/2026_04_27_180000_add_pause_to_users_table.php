<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I2 — Sistema de pausa do corretor.
 *
 * Separado de cooldown_until (que é cooldown automático pós-recebimento de lead).
 * Esse aqui é pausa MANUAL do corretor (almoço, reunião, etc).
 *
 *   paused_until   — quando expira a pausa (auto-resume). NULL = não pausado.
 *   pause_reason   — texto livre / preset (almoço, reunião, etc) pra UI/audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('paused_until')->nullable()->after('cooldown_until');
            $table->string('pause_reason', 80)->nullable()->after('paused_until');
            $table->index('paused_until', 'idx_users_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_paused_until');
            $table->dropColumn(['paused_until', 'pause_reason']);
        });
    }
};
