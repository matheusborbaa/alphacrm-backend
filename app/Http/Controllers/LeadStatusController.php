<?php

namespace App\Http\Controllers;

use App\Models\LeadStatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de lead_status usado pela tela admin de configuração.
 *
 * A leitura (index) continua disponível em /lead-status pra autenticados;
 * aqui expomos operações de escrita pra quem tem custom_fields.manage
 * (ou status_required_fields.manage — usamos a mesma permission "configurar pipeline").
 */
class LeadStatusController extends Controller
{
    public function index()
    {
        return LeadStatus::select('id', 'name', 'order', 'color_hex')
            ->withCount('leads')
            ->orderBy('order')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        // order default = último + 1
        if (!array_key_exists('order', $data) || is_null($data['order'])) {
            $data['order'] = (int) (LeadStatus::max('order') ?? 0) + 1;
        }

        $status = LeadStatus::create($data);

        return response()->json($status, 201);
    }

    public function update(Request $request, LeadStatus $leadStatus)
    {
        $data = $this->validateData($request, $leadStatus->id);
        $leadStatus->update($data);

        return $leadStatus;
    }

    /**
     * Só deleta status que não tem nenhum lead vinculado
     * e nenhum substatus (que por sua vez protege seus próprios leads).
     */
    public function destroy(LeadStatus $leadStatus)
    {
        $leadsCount = Lead::where('status_id', $leadStatus->id)->count();
        if ($leadsCount > 0) {
            throw ValidationException::withMessages([
                'status' => "Não é possível excluir: existem {$leadsCount} lead(s) com esse status.",
            ]);
        }

        if ($leadStatus->substatus()->count() > 0) {
            throw ValidationException::withMessages([
                'status' => 'Remova os substatus vinculados antes de excluir.',
            ]);
        }

        $leadStatus->delete();
        return response()->json(['deleted' => true]);
    }

    /**
     * POST /admin/lead-status/reorder
     * Body: { items: [{id, order}, ...] }
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.id'       => 'required|exists:lead_status,id',
            'items.*.order'    => 'required|integer|min:0',
        ]);

        foreach ($data['items'] as $item) {
            LeadStatus::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'      => ['required', 'string', 'max:255', Rule::unique('lead_status', 'name')->ignore($ignoreId)],
            'order'     => 'nullable|integer|min:0',
            // Hex colorido: exige 7 chars começando com # (ex: #3B82F6)
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }
}
