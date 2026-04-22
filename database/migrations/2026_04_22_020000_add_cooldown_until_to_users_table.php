<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema de cooldown pós-recebimento de lead.
 *
 * Quando o rodízio entrega um lead pra um corretor, se a feature estiver
 * habilitada em settings (lead_cooldown_enabled = true e lead_cooldown_minutes > 0),
 * marcamos o corretor como "ocupado" e gravamos `cooldown_until = now() + X min`.
 *
 * Esse timestamp é lido:
 *  - Em LeadAssignmentService@isEligible: corretor com cooldown_until no futuro
 *    NÃO é elegível, mesmo que `status_corretor = 'disponivel'`.
 *  - Pelo command `leads:release-cooldowns` (agendado a cada 1 min): libera os
 *    corretores cujo cooldown expirou, volta status pra 'disponivel' e chama
 *    tryClaimNextOrphan pra pegar leads órfãos acumulados.
 *  - No frontend (sidebar.js): mostra contagem regressiva + desabilita o select
 *    enquanto cooldown_until > now().
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('cooldown_until')->nullable()->after('last_lead_assigned_at');
            $table->index('cooldown_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['cooldown_until']);
            $table->dropColumn('cooldown_until');
        });
    }
};
