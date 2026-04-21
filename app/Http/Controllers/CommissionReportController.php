<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * @group Relatórios de Comissões
 *
 * Relatórios financeiros de comissões por corretor.
 * Usado por gestores e administradores.
 */
class CommissionReportController extends Controller
{
// CommissionReportController.php

/**
 * @group Comissões
 *
 * Retorna as comissões do corretor logado
 *
 * @authenticated
 */
public function myCommissions(Request $request)
{
    $user = $request->user();

    $query = \App\Models\Commission::with('lead');

    // Se NÃO for admin nem gestor → filtra pelo próprio usuário
    if (!in_array($user->role, ['admin', 'gestor'])) {
        $query->where('user_id', $user->id);
    }

    return response()->json(
        $query->orderByDesc('created_at')->get()
    );
}


    /**
     * Relatório de comissões
     *
     * Retorna comissões por período e corretor,
     * incluindo valores totais e status de pagamento.
     *
     * @queryParam start_date date Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam end_date date Data final (YYYY-MM-DD). Example: 2026-01-31
     * @queryParam user_id int Filtrar por corretor. Example: 5
     * @queryParam status string Filtrar por status (pending, paid). Example: pending
     *
     * @response 200 {
     *   "total_sales": 1200000,
     *   "total_commissions": 60000,
     *   "items": [
     *     {
     *       "corretor": "João Silva",
     *       "sale_value": 300000,
     *       "commission_value": 15000,
     *       "status": "pending"
     *     }
     *   ]
     * }
     */
    public function index(Request $request)
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $query = Commission::with('corretor')
            ->whereBetween('created_at', [$start, $end]);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $commissions = $query->get();

        return response()->json([
            'total_sales' => $commissions->sum('sale_value'),
            'total_commissions' => $commissions->sum('commission_value'),
            'items' => $commissions->map(function ($c) {
                return [
                    'corretor' => $c->corretor->name,
                    'sale_value' => $c->sale_value,
                    'commission_value' => $c->commission_value,
                    'status' => $c->status
                ];
            })
        ]);
    }
}
