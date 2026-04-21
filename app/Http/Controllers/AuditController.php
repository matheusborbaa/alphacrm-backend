<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Lead;

/**
 * @group Auditoria
 *
 * Histórico de ações realizadas no sistema.
 * Usado para rastreabilidade, compliance e análise de alterações nos leads.
 */
class AuditController extends Controller
{
    /**
     * Histórico de auditoria do lead
     *
     * Retorna a linha do tempo completa de ações realizadas em um lead,
     * incluindo mudanças de status, redistribuições de SLA e contatos.
     *
     * @urlParam lead int ID do lead. Example: 1
     *
     * @response 200 [
     *   {
     *     "id": 10,
     *     "event": "status_changed",
     *     "entity_type": "Lead",
     *     "entity_id": 1,
     *     "user": {
     *       "id": 2,
     *       "name": "Gestor João"
     *     },
     *     "old_values": {
     *       "status_id": 2
     *     },
     *     "new_values": {
     *       "status_id": 3
     *     },
     *     "source": "kanban",
     *     "created_at": "2026-01-27T15:22:00Z"
     *   }
     * ]
     */
    public function index(Lead $lead)
    {
        return response()->json(
            Audit::where('entity_type', 'Lead')
                ->where('entity_id', $lead->id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->get()
        );
    }
}
