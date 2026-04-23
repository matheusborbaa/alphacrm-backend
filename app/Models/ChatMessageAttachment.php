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
     *
     * Para lead_document, o `availability` é computado AO VIVO — se o
     * documento-fonte foi excluído (ou teve exclusão solicitada), o card
     * no chat reflete isso mesmo que a mensagem seja antiga. O snapshot
     * continua congelado (histórico imutável), mas o link de preview e o
     * estado de "clicável" seguem o recurso real.
     */
    public function buildPayload(): array
    {
        $availability = $this->resolveAvailability();

        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'attachable_id'  => $this->attachable_id,
            'original_name'  => $this->original_name,
            'mime_type'      => $this->mime_type,
            'size_bytes'     => $this->size_bytes,
            'snapshot'       => $this->snapshot ?? [],
            // Sprint 4.x — estado vivo do recurso. Só `lead_document` muda
            // com o tempo (pode ser deletado/purgado). Outros tipos sempre
            // retornam state='available' — frontend pode ignorar.
            'availability'   => $availability,
            // URL de preview só pros tipos que têm arquivo (upload, lead_document).
            // Se o lead_document está indisponível, preview_url vira null e o
            // frontend renderiza um card desabilitado (não clicável).
            'preview_url'    => $this->resolvePreviewUrl($availability),
        ];
    }

    /**
     * Computa o estado vivo do recurso anexado.
     *
     * Para `lead_document`: consulta a tabela lead_documents pra saber se o
     * doc ainda existe, se está com solicitação de exclusão pendente ou já
     * foi soft-deletado. Quatro estados possíveis:
     *   - available         : doc ok, card clicável
     *   - pending_deletion  : corretor solicitou exclusão, aguarda admin
     *   - deleted           : admin aprovou (soft-delete, ainda restaurável)
     *   - purged            : row sumiu do banco (purge job ou hard delete)
     *
     * Para os outros tipos, sempre `available` — o snapshot é autoritativo
     * (lead e empreendimento podem ser renomeados, mas o card continua
     * mostrando o snapshot congelado e é clicável pra página atual).
     */
    private function resolveAvailability(): array
    {
        if ($this->type !== self::TYPE_LEAD_DOCUMENT || !$this->attachable_id) {
            return ['state' => 'available'];
        }

        // Lookup direto por PK. No feed do chat (50 msgs), o pior caso é
        // ~50 queries de PK — custo desprezível em MySQL. Evitamos cache
        // estático pra não arriscar staleness entre requests no PHP-FPM.
        // Se virar gargalo, otimização correta é eager-load em lote no
        // controller e injetar via setter antes do buildPayload.
        $doc = LeadDocument::find((int) $this->attachable_id);
        return $this->computeLeadDocumentAvailability($doc);
    }

    private function computeLeadDocumentAvailability(?LeadDocument $doc): array
    {
        if (!$doc) {
            return [
                'state'   => 'purged',
                'message' => 'Documento excluído permanentemente.',
            ];
        }
        if ($doc->deleted_at !== null) {
            return [
                'state'   => 'deleted',
                'message' => 'Documento excluído.',
            ];
        }
        if ($doc->isDeletionPending()) {
            return [
                'state'   => 'pending_deletion',
                'message' => 'Solicitação de exclusão em andamento.',
            ];
        }
        return ['state' => 'available'];
    }

    /**
     * URL de preview/download relativa. Null pros tipos "referência"
     * (lead, empreendimento) — esses são clicáveis pra página deles
     * diretamente no frontend, não precisam de preview blob.
     *
     * Para lead_document indisponível (pending_deletion / deleted / purged),
     * também devolve null: o card vira não-clicável e o frontend mostra o
     * motivo em cima dele.
     */
    private function resolvePreviewUrl(?array $availability = null): ?string
    {
        if ($this->type === self::TYPE_UPLOAD) {
            return "/chat/attachments/{$this->id}/download";
        }
        if ($this->type === self::TYPE_LEAD_DOCUMENT && $this->attachable_id) {
            // Se o doc-fonte ficou indisponível, ninguém clica nesse card.
            $state = $availability['state'] ?? 'available';
            if ($state !== 'available') {
                return null;
            }
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
