<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;


// Cleanup: leads com lead_substatus_id apontando pra um substatus que NÃO pertence ao status atual.
// Esse cenário fazia o card sumir do kanban (combinação status+substatus inválida).
// O bug foi corrigido nos controllers (LeadController::firstContact + KanbanController::move),
// essa migration só limpa o que ficou órfão antes do fix.
return new class extends Migration {
    public function up(): void
    {
        try {
            $affected = DB::statement("
                UPDATE leads l
                LEFT JOIN lead_substatus s ON s.id = l.lead_substatus_id
                SET l.lead_substatus_id = NULL
                WHERE l.lead_substatus_id IS NOT NULL
                  AND (s.id IS NULL OR s.lead_status_id <> l.status_id)
            ");
        } catch (\Throwable $e) {

        }
    }

    public function down(): void
    {

    }
};
