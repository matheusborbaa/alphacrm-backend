<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Empreendimento;
use App\Services\LeadAssignmentService;

/**
 * @group ManyChat
 */


class ManyChatWebhookController extends Controller
{
/**
 * Webhook ManyChat � Cria��o de Lead
 *
 * Recebe dados enviados pelo ManyChat, cria ou atualiza um lead,
 * associa empreendimento (se informado) e executa o rod�zio autom�tico.
 *
 * @bodyParam phone string required Telefone do lead. Example: 11999999999
 * @bodyParam name string Nome do lead. Example: Jo�o Silva
 * @bodyParam email string Email do lead. Example: joao@email.com
 * @bodyParam channel string Canal de origem. Example: Instagram
 * @bodyParam campaign string Campanha. Example: Black Friday
 * @bodyParam empreendimento string Nome do empreendimento. Example: Residencial Alpha
 * @bodyParam manychat_id string ID do ManyChat. Example: mc_123456
 *
 * @response 200 {
 *   "success": true,
 *   "lead_id": 42
 * }
 */
    public function store(Request $request)
    {
        // Valida��o m�nima
        $data = $request->validate([
            'phone'          => 'required|string',
            'name'           => 'nullable|string',
            'email'          => 'nullable|string',
            'channel'        => 'nullable|string',
            'campaign'       => 'nullable|string',
            'empreendimento' => 'nullable|string',
            'manychat_id'    => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Origem (ManyChat)
            $source = LeadSource::firstOrCreate([
                'name' => 'ManyChat'
            ]);

            // Status inicial
            $status = LeadStatus::firstOrCreate([
                'name' => 'Novo'
            ], [
                'order' => 1
            ]);

            // Deduplica��o por telefone
            $lead = Lead::where('phone', $data['phone'])->first();

            if (!$lead) {
                $lead = Lead::create([
                    'name'        => $data['name'] ?? null,
                    'phone'       => $data['phone'],
                    'email'       => $data['email'] ?? null,
                    'source_id'   => $source->id,
                    'status_id'   => $status->id,
                    'manychat_id' => $data['manychat_id'] ?? null,
                    'channel'     => $data['channel'] ?? null,
                    'campaign'    => $data['campaign'] ?? null,
                ]);
            } else {
                // Atualiza dados se o lead j� existir
                $lead->update([
                    'name'     => $data['name'] ?? $lead->name,
                    'email'    => $data['email'] ?? $lead->email,
                    'campaign' => $data['campaign'] ?? $lead->campaign,
                ]);
            }

            // Empreendimento (opcional)
            if (!empty($data['empreendimento'])) {
                $empreendimento = Empreendimento::firstOrCreate([
                    'name' => $data['empreendimento']
                ]);

                if (!$lead->empreendimentos()->where('empreendimento_id', $empreendimento->id)->exists()) {
                    $lead->empreendimentos()->attach($empreendimento->id, [
                        'priority' => 1
                    ]);
                }
            }

            // Rodizio automático
            $assignmentService = new LeadAssignmentService();
            $assignmentService->assign($lead);

            DB::commit();

            return response()->json([
                'success' => true,
                'lead_id' => $lead->id
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
