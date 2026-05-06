<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;


// One-shot: zera lead_substatus_id órfão (sub não pertence ao status atual).
// O bug ficava sumindo card do kanban; corrigido em LeadController::firstContact
// e KanbanController::move — esta migration só faz a faxina do que ficou pra trás.
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
