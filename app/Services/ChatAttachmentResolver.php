<?php

namespace App\Services;

use App\Models\ChatMessageAttachment;
use App\Models\Empreendimento;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Dada uma descrição "quero anexar X do tipo Y", resolve a fonte, valida
 * permissões/existência e retorna o snapshot JSON pra persistir.
 *
 * Importante: o snapshot é IMUTÁVEL depois de salvo. Se um lead for renomeado
 * amanhã, o anexo continua mostrando o nome de quando foi enviado. Isso é
 * feature (histórico de chat auditável), não bug.
 *
 * Permissões: por enquanto tolerante — qualquer corretor logado pode anexar
 * qualquer lead/empreendimento/doc. Se virar necessidade (ex: corretor não
 * ver leads de outros), adiciona check aqui.
 */
class ChatAttachmentResolver
{
    /**
     * Resolve um item "não-upload" (referência). Retorna array com:
     *   - attachable_id
     *   - snapshot (dados pro card)
     *   - type (o mesmo recebido, pra conveniência)
     *
     * Lança ValidationException se o recurso não existir.
     */
    public function resolveReference(string $type, int $id): array
    {
        return match ($type) {
            ChatMessageAttachment::TYPE_LEAD            => $this->resolveLead($id),
            ChatMessageAttachment::TYPE_EMPREENDIMENTO  => $this->resolveEmpreendimento($id),
            ChatMessageAttachment::TYPE_LEAD_DOCUMENT   => $this->resolveLeadDocument($id),
            default                                     => throw ValidationException::withMessages([
                'attachments' => "Tipo de anexo inválido: {$type}",
            ]),
        };
    }

    private function resolveLead(int $id): array
    {
        $lead = Lead::with(['status:id,name,color_hex', 'corretor:id,name'])->find($id);
        if (!$lead) {
            throw ValidationException::withMessages([
                'attachments' => "Lead #{$id} não encontrado.",
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

    private function resolveLeadDocument(int $id): array
    {
        $doc = LeadDocument::with('lead:id,name')->find($id);
        if (!$doc) {
            throw ValidationException::withMessages([
                'attachments' => "Documento #{$id} não encontrado.",
            ]);
        }
        // Não permitimos anexar doc já soft-deletado (pending purge).
        if ($doc->deleted_at !== null) {
            throw ValidationException::withMessages([
                'attachments' => "Documento #{$id} foi removido e não pode ser anexado.",
            ]);
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
