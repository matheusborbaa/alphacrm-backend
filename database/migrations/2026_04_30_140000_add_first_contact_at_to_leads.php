<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


// Coluna nativa pra timestamp do primeiro contato. Antes só tinha sla_status enum;
// agora dá pra calcular tempo médio até o primeiro contato, ranking de velocidade etc.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('first_contact_at')->nullable()->after('sla_status');
            $table->index('first_contact_at', 'idx_leads_first_contact');
        });


        // Backfill: leads que já têm sla_status='met' ganham um first_contact_at aproximado
        // baseado em LeadHistory tipo first_contact (ou last_interaction_at como fallback).
        try {
            DB::statement("
                UPDATE leads l
                LEFT JOIN (
                    SELECT lead_id, MIN(created_at) AS at
                    FROM lead_history
                    WHERE type = 'first_contact'
                    GROUP BY lead_id
                ) h ON h.lead_id = l.id
                SET l.first_contact_at = COALESCE(h.at, l.last_interaction_at, l.updated_at)
                WHERE l.sla_status = 'met'
                  AND l.first_contact_at IS NULL
            ");
        } catch (\Throwable $e) {

        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('idx_leads_first_contact');
            $table->dropColumn('first_contact_at');
        });
    }
};
