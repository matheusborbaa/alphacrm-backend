<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Http\Request;
use App\Services\AuditService;

/**
 * @group Kanban
 *
 * Funil visual de leads (drag & drop).
 * Usado na tela Kanban do CRM.
 */
class KanbanController extends Controller
{
    /**
     * Listar colunas do Kanban
     *
     * Retorna os status do funil com seus respectivos leads.
     * Usado para montar o Kanban visual.
     *
     * @response 200 [
     *   {
     *     "id": 1,
     *     "name": "Novo",
     *     "leads": [
     *       {
     *         "id": 10,
     *         "name": "João Silva",
     *         "phone": "11999999999",
     *         "sla_status": "pending"
     *       }
     *     ]
     *   }
     * ]
     */
   public function index()
{
    $user = auth()->user();

    $statuses = LeadStatus::orderBy('order')
        ->with(['leads' => function ($q) use ($user) {

            if (!in_array($user->role, ['admin', 'gestor'])) {
                $q->where('assigned_user_id', $user->id);
            }

            $q->orderBy('position')
              ->select(
                  'id',
                  'name',
                  'phone',
                  'sla_status',
                  'status_id',
                  'assigned_user_id',
                  'position'
              );
        }])
        ->get(['id', 'name']);

    return response()->json($statuses->values());
}




    /**
     * Mover lead no Kanban
     *
     * Atualiza o status de um lead quando ele é movido no Kanban.
     *
     * @urlParam lead int ID do lead. Example: 10
     *
     * @bodyParam status_id int required ID do novo status do lead. Example: 3
     *
     * @response 200 {
     *   "success": true
     * }
     *
     * @response 404 {
     *   "message": "Lead not found."
     * }
     */
    public function move(Request $request, Lead $lead)
{
    $data = $request->validate([
        'status_id' => 'required|exists:lead_status,id'
    ]);

    // pega última posição da nova coluna
    $lastPosition = Lead::where('status_id', $data['status_id'])
        ->max('position');

    $lead->update([
        'status_id' => $data['status_id'],
        'position' => ($lastPosition ?? 0) + 1
    ]);

    return response()->json(['success' => true]);
}

public function reorder(Request $request)
{
    foreach ($request->leads as $leadData) {

        Lead::where('id', $leadData['id'])
            ->update([
                'position' => $leadData['position']
            ]);
    }

    return response()->json(['success' => true]);
}
}
