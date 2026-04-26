<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;

class KanbanController extends Controller
{
    public function index()
    {

        $statuses = LeadStatus::orderBy('order')->get();

        $leads = Lead::select(
                'id',
                'name',
                'sla_status',
                'status_id',
                'created_at'
            )
            ->get();

        return response()->json([
            'statuses' => $statuses,
            'leads' => $leads
        ]);
    }
}