<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

            'availability'   => $availability,

            'preview_url'    => $this->resolvePreviewUrl($availability),
        ];
    }

    private function resolveAvailability(): array
    {
        if ($this->type !== self::TYPE_LEAD_DOCUMENT || !$this->attachable_id) {
            return ['state' => 'available'];
        }

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

    private function resolvePreviewUrl(?array $availability = null): ?string
    {
        if ($this->type === self::TYPE_UPLOAD) {
            return "/chat/attachments/{$this->id}/download";
        }
        if ($this->type === self::TYPE_LEAD_DOCUMENT && $this->attachable_id) {

            $state = $availability['state'] ?? 'available';
            if ($state !== 'available') {
                return null;
            }

            return "/chat/attachments/{$this->id}/lead-document";
        }
        return null;
    }
}
