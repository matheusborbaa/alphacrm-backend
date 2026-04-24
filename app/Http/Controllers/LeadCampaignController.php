<?php

namespace App\Http\Controllers;

use App\Models\LeadCampaign;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadCampaignController extends Controller
{
    public function index()
    {
        return LeadCampaign::orderBy('name')->get(['id', 'name']);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $campaign = LeadCampaign::create($data);
        return response()->json($campaign, 201);
    }

    public function update(Request $request, LeadCampaign $leadCampaign)
    {
        $data = $this->validateData($request, $leadCampaign->id);
        $leadCampaign->update($data);
        return $leadCampaign;
    }

    public function destroy(LeadCampaign $leadCampaign)
    {
        $count = Lead::where('campaign', $leadCampaign->name)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'campaign' => "Não é possível excluir: existem {$count} lead(s) com essa campanha.",
            ]);
        }
        $leadCampaign->delete();
        return response()->json(['deleted' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('lead_campaigns', 'name')->ignore($ignoreId),
            ],
        ]);
    }
}
