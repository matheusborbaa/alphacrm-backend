<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anexo de uma ChatMessage. Um dos 4 tipos definidos em TYPES.
 *
 * A chave de design é o `snapshot`: JSON com os campos que o frontend precisa
 * renderizar o card (nome, imagem de capa, etc). Congelado no momento do
 * attach — se o lead for renomeado/deletado depois, o anexo continua mostrando
 * o snapshot de quando foi enviado (histórico imutável).
 *
 * buildPayload() centraliza a shape devolvida pro frontend em todos os
 * endpoints que retornam msg com anexo (ChatMessageController@store, @index).
 * Sempre usar o mesmo método pra garantir consistência.
 */
class ChatMessageAttachment extends Model
{
    use HasFactory;

    public const TYPE_LEAD          = 'lead';
    public const TYPE_EMPREENDIMENTO = 'empreendimento';
    public const TYPE_LEAD_DOCUMENT = 'lead_document';
    public const TYPE_UPLOAD        = 'upload';

    public const TYPES = [
        self::TYPE_LEAD,
        self::TYPE_EMPREENDIMENTO,
        self::TYPE_LEAD_DOCUMENT,
        self::TYPE_UPLOAD,
    ];

    protected $fillable = [
        'message_id',
        'type',
        'attachable_id',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploader_user_id',
        'snapshot',
    ];

    protected $casts = [
        'attachable_id'    => 'integer',
        'size_bytes'       => 'integer',
        'uploader_user_id' => 'integer',
        'snapshot'         => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    /**
     * Shape unificado pro frontend. Garante que TODOS os lugares que
     * devolvem anexo (store, index, polling) usem o mesmo formato.
     *
     * O frontend decide o render a partir do `type`. Campos específicos
     * de cada tipo vão no `snapshot`, mas colunas diretas (size_bytes,
     * mime_type) ficam também no nível raiz pra conveniência.
     */
    public function buildPayload(): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'attachable_id'  => $this->attachable_id,
            'original_name'  => $this->original_name,
            'mime_type'      => $this->mime_type,
            'size_bytes'     => $this->size_bytes,
            'snapshot'       => $this->snapshot ?? [],
            // URL de preview só pros tipos que têm arquivo (upload, lead_document).
            // O frontend monta o href relativo — backend não sabe base URL
            // pública confiável (tá atrás de proxy, etc).
            'preview_url'    => $this->resolvePreviewUrl(),
        ];
    }

    /**
     * URL de preview/download relativa. Null pros tipos "referência"
     * (lead, empreendimento) — esses são clicáveis pra página deles
     * diretamente no frontend, não precisam de preview blob.
     */
    private function resolvePreviewUrl(): ?string
    {
        if ($this->type === self::TYPE_UPLOAD) {
            return "/chat/attachments/{$this->id}/download";
        }
        if ($this->type === self::TYPE_LEAD_DOCUMENT && $this->attachable_id) {
            // Reaproveita o endpoint existente de preview de doc do lead.
            // A rota real é /leads/{lead}/documents/{document}/preview —
            // o lead_id vem congelado no snapshot (ChatAttachmentResolver).
            $leadId = $this->snapshot['lead_id'] ?? null;
            if ($leadId) {
                return "/leads/{$leadId}/documents/{$this->attachable_id}/preview";
            }
            // Fallback: snapshot antigo sem lead_id — nada a fazer.
            return null;
        }
        return null;
    }
}
