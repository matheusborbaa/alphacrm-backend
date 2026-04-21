<?php

namespace App\Http\Controllers;

use App\Models\LeadSubstatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de lead_substatus (etapas detalhadas dentro de um status).
 * Usado pela tela admin de configuração do pipeline.
 */
class LeadSubstatusController extends Controller
{
    public function index(Request $request)
    {
        $query = LeadSubstatus::query()->with('status:id,name,order');

        if ($request->filled('status_id')) {
            $query->where('lead_status_id', $request->status_id);
        }

        return $query->orderBy('lead_status_id')->orderBy('order')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        if (!array_key_exists('order', $data) || is_null($data['order'])) {
            $data['order'] = (int) (LeadSubstatus::where('lead_status_id', $data['lead_status_id'])->max('order') ?? 0) + 1;
        }

        $sub = LeadSubstatus::create($data);
        $sub->load('status:id,name');

        return response()->json($sub, 201);
    }

    public function update(Request $request, LeadSubstatus $leadSubstatus)
    {
        $data = $this->validateData($request, $leadSubstatus->id);
        $leadSubstatus->update($data);
        $leadSubstatus->load('status:id,name');

        return $leadSubstatus;
    }

    public function destroy(LeadSubstatus $leadSubstatus)
    {
        $leadsCount = Lead::where('lead_substatus_id', $leadSubstatus->id)->count();
        if ($leadsCount > 0) {
            throw ValidationException::withMessages([
                'substatus' => "Não é possível excluir: existem {$leadsCount} lead(s) nesse substatus.",
            ]);
        }

        $leadSubstatus->delete();
        return response()->json(['deleted' => true]);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.id'    => 'required|exists:lead_substatus,id',
            'items.*.order' => 'required|integer|min:0',
        ]);

        foreach ($data['items'] as $item) {
            LeadSubstatus::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'lead_status_id' => 'required|exists:lead_status,id',
            'name'           => [
                'required',
                'string',
                'max:255',
                Rule::unique('lead_substatus', 'name')
                    ->where(fn($q) => $q->where('lead_status_id', $request->input('lead_status_id')))
                    ->ignore($ignoreId),
            ],
            'order'          => 'nullable|integer|min:0',
        ]);
    }
}
