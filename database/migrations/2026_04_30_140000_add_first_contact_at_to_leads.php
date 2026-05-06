<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


// Antes só tinha o enum sla_status; com o timestamp dá pra medir tempo médio e fazer ranking.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('first_contact_at')->nullable()->after('sla_status');
            $table->index('first_contact_at', 'idx_leads_first_contact');
        });


        // Backfill aproximado pra quem já estava com sla_status=met. Histórico > last_interaction > updated.
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
