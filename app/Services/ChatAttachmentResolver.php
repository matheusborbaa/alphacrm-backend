<?php

namespace App\Services;

use App\Models\ChatMessageAttachment;
use App\Models\Empreendimento;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChatAttachmentResolver
{

    public function resolveReference(string $type, int $id, ?User $peerUser = null): array
    {
        return match ($type) {
            ChatMessageAttachment::TYPE_LEAD            => $this->resolveLead($id, $peerUser),
            ChatMessageAttachment::TYPE_EMPREENDIMENTO  => $this->resolveEmpreendimento($id),
            ChatMessageAttachment::TYPE_LEAD_DOCUMENT   => $this->resolveLeadDocument($id, $peerUser),
            default                                     => throw ValidationException::withMessages([
                'attachments' => "Tipo de anexo inválido: {$type}",
            ]),
        };
    }

    private function resolveLead(int $id, ?User $peerUser = null): array
    {
        $lead = Lead::with(['status:id,name,color_hex', 'corretor:id,name'])->find($id);
        if (!$lead) {
            throw ValidationException::withMessages([
                'attachments' => "Lead #{$id} não encontrado.",
            ]);
        }

        $me = Auth::user();
        if ($me && !$lead->isVisibleTo($me)) {
            throw ValidationException::withMessages([
                'attachments' => "Você não tem acesso ao lead #{$id}.",
            ]);
        }
        if ($peerUser && !$lead->isVisibleTo($peerUser)) {
            throw ValidationException::withMessages([
                'attachments' => "O destinatário não tem acesso ao lead #{$id}. Peça pro gestor atribuir o lead ou envie como mensagem de texto.",
            ]);
        }

        return [
            'type'           => ChatMessageAttachment::TYPE_LEAD,
            'attachable_id'  => $lead->id,
            'snapshot'       => [
                'name'       => $lead->name,
                'phone'      => $lead->phone,
                'etapa'      => $lead->status?->name,
                'color_hex'  => $lead->status?->color_hex,
                'corretor'   => $lead->corretor?->name,
                'value'      => $lead->value,
            ],
        ];
    }

    private function resolveEmpreendimento(int $id): array
    {
        $emp = Empreendimento::find($id);
        if (!$emp) {
            throw ValidationException::withMessages([
                'attachments' => "Empreendimento #{$id} não encontrado.",
            ]);
        }

        return [
            'type'          => ChatMessageAttachment::TYPE_EMPREENDIMENTO,
            'attachable_id' => $emp->id,
            'snapshot'      => [
                'name'          => $emp->name,
                'code'          => $emp->code,
                'city'          => $emp->locationcity,
                'neighborhood'  => $emp->neighborhood,
                'cover_image'   => $emp->cover_image,
                'initial_price' => $emp->initial_price,
                'status'        => $emp->status,
            ],
        ];
    }

    private function resolveLeadDocument(int $id, ?User $peerUser = null): array
    {
        $doc = LeadDocument::with('lead:id,name,assigned_user_id')->find($id);
        if (!$doc) {
            throw ValidationException::withMessages([
                'attachments' => "Documento #{$id} não encontrado.",
            ]);
        }

        if ($doc->deleted_at !== null) {
            throw ValidationException::withMessages([
                'attachments' => "Documento #{$id} foi removido e não pode ser anexado.",
            ]);
        }

        if ($doc->lead) {

            $me = Auth::user();
            if ($me && !$doc->lead->isVisibleTo($me)) {
                throw ValidationException::withMessages([
                    'attachments' => "Você não tem acesso ao documento #{$id}.",
                ]);
            }
            if ($peerUser && !$doc->lead->isVisibleTo($peerUser)) {
                throw ValidationException::withMessages([
                    'attachments' => "O destinatário não tem acesso ao documento #{$id}.",
                ]);
            }
        }

        return [
            'type'          => ChatMessageAttachment::TYPE_LEAD_DOCUMENT,
            'attachable_id' => $doc->id,
            'snapshot'      => [
                'original_name' => $doc->original_name,
                'mime_type'     => $doc->mime_type,
                'size_bytes'    => $doc->size_bytes,
                'category'      => $doc->category,
                'lead_id'       => $doc->lead_id,
                'lead_name'     => $doc->lead?->name,
            ],
        ];
    }
}
