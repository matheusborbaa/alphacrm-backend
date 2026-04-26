<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Lead;

class AuditController extends Controller
{

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
