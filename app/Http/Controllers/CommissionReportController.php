<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CommissionReportController extends Controller
{

public function myCommissions(Request $request)
{
    $user = $request->user();

    $query = \App\Models\Commission::with('lead')->whereHas('lead');

    if (!in_array($user->role, ['admin', 'gestor'])) {
        $query->where('user_id', $user->id);
    }

    return response()->json(
        $query->orderByDesc('created_at')->get()
    );
}

    public function index(Request $request)
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $query = Commission::with('corretor')
            ->whereHas('lead')
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
