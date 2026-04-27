<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pausa manual do corretor (almoço, reunião). Separado do cooldown_until pra não misturar
// com o cooldown automático pós-recebimento de lead.
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
