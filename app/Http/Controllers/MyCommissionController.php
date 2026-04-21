<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Commission;

class MyCommissionController extends Controller
{
    /**
     * @group Corretor - Comissões
     *
     * Lista as próximas comissões do corretor logado.
     *
     * Retorna apenas comissões vinculadas ao usuário autenticado.
     *
     * @authenticated
     *
     * @response 200 {
     *   "total": 3500,
     *   "items": [
     *     {
     *       "id": 1,
     *       "lead_id": 12,
     *       "status": "prevista",
     *       "sale_value": 450000,
     *       "commission_percentage": 5,
     *       "commission_value": 22500,
     *       "paid_at": null,
     *       "created_at": "2026-02-07 10:00:00"
     *     }
     *   ]
     * }
     */
    public function upcoming(Request $request)
    {
        $user = $request->user();

        $commissions = Commission::where('user_id', $user->id)
            ->whereIn('status', ['prevista', 'pendente'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'total' => $commissions->sum('commission_value'),
            'items' => $commissions->map(fn ($c) => [
                'id' => $c->id,
                'lead_id' => $c->lead_id,
                'status' => $c->status,
                'sale_value' => $c->sale_value,
                'commission_percentage' => $c->commission_percentage,
                'commission_value' => $c->commission_value,
                'paid_at' => $c->paid_at,
                'created_at' => $c->created_at,
            ])
        ]);
    }
}
