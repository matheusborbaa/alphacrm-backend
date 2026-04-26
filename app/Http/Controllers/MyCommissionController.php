<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Commission;

class MyCommissionController extends Controller
{

    public function upcoming(Request $request)
    {
        $user = $request->user();

        $commissions = Commission::where('user_id', $user->id)
            ->whereHas('lead')
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
