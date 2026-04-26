<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Empreendimento;
use App\Services\LeadAssignmentService;

class ManyChatWebhookController extends Controller
{

    public function store(Request $request)
    {

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

            $source = LeadSource::firstOrCreate([
                'name' => 'ManyChat'
            ]);

            $status = LeadStatus::firstOrCreate([
                'name' => 'Novo'
            ], [
                'order' => 1
            ]);



            $phoneDigits = Lead::normalizePhone($data['phone']);
            $lead = $phoneDigits
                ? Lead::where('phone_normalized', $phoneDigits)
                      ->orWhere('whatsapp_normalized', $phoneDigits)
                      ->first()
                : null;

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

                $lead->update([
                    'name'     => $data['name'] ?? $lead->name,
                    'email'    => $data['email'] ?? $lead->email,
                    'campaign' => $data['campaign'] ?? $lead->campaign,
                ]);
            }

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
