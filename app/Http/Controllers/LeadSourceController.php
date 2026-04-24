<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadSourceController extends Controller
{
    public function index()
    {
        return LeadSource::orderBy('name')->get(['id', 'name']);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $source = LeadSource::create($data);
        return response()->json($source, 201);
    }

    public function update(Request $request, LeadSource $leadSource)
    {
        $data = $this->validateData($request, $leadSource->id);
        $leadSource->update($data);
        return $leadSource;
    }

    public function destroy(LeadSource $leadSource)
    {
        $count = Lead::where('source_id', $leadSource->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'source' => "Não é possível excluir: existem {$count} lead(s) com essa origem.",
            ]);
        }
        $leadSource->delete();
        return response()->json(['deleted' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('lead_sources', 'name')->ignore($ignoreId),
            ],
        ]);
    }
}
