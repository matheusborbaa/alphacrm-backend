<?php

namespace App\Http\Controllers;

use App\Models\ChatMessageAttachment;
use App\Models\Commission;
use App\Models\CustomField;
use App\Models\Empreendimento;
use App\Models\EmpreendimentoImage;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminFilesController extends Controller
{

    public function index(Request $request)
    {
        $q       = trim((string) $request->input('q', ''));
        $type    = (string) $request->input('type', '');
        $from    = $request->input('from');
        $to      = $request->input('to');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(10, (int) $request->input('per_page', 30)));

        $allRows = collect()
            ->merge($this->safeCollect('lead_documents',        fn () => $this->fromLeadDocuments()))
            ->merge($this->safeCollect('custom_field_files',    fn () => $this->fromCustomFieldFiles()))
            ->merge($this->safeCollect('chat_attachments',      fn () => $this->fromChatAttachments()))
            ->merge($this->safeCollect('empreendimento_docs',   fn () => $this->fromEmpreendimentoDocs()))
            ->merge($this->safeCollect('empreendimento_images', fn () => $this->fromEmpreendimentoImages()))
            ->merge($this->safeCollect('user_avatars',          fn () => $this->fromUserAvatars()))
            ->merge($this->safeCollect('commission_receipts',   fn () => $this->fromCommissionReceipts()));

        $globalCount = $allRows->count();
        $globalBytes = $allRows->sum(fn ($r) => (int) ($r['size_bytes'] ?? 0));

        $rows = $allRows;
        if ($type) {
            $rows = $rows->filter(fn ($r) => $r['type'] === $type);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) =>
                mb_stripos((string) ($r['original_name'] ?? ''), $needle) !== false
                || mb_stripos((string) ($r['context_label'] ?? ''), $needle) !== false
            );
        }
        if ($from) {
            $rows = $rows->filter(fn ($r) => $r['created_at'] && $r['created_at'] >= $from);
        }
        if ($to) {
            $rows = $rows->filter(fn ($r) => $r['created_at'] && $r['created_at'] <= $to);
        }

        $totalBytes = $rows->sum(fn ($r) => (int) ($r['size_bytes'] ?? 0));
        $totalCount = $rows->count();

        $rows = $rows->sortByDesc('created_at')->values();

        $sliced = $rows->forPage($page, $perPage)->values();

        return response()->json([
            'data'           => $sliced,
            'total'          => $totalCount,
            'total_bytes'    => $totalBytes,
            'global_count'   => $globalCount,
            'global_bytes'   => $globalBytes,
            'page'           => $page,
            'per_page'       => $perPage,
            'last_page'      => (int) ceil(max(1, $totalCount) / $perPage),
        ]);
    }

    private function fromLeadDocuments(): Collection
    {
        return LeadDocument::query()
            ->with(['lead:id,name', 'uploader:id,name'])

            ->whereHas('lead')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'             => 'leaddoc-' . $d->id,
                'type'           => 'lead_document',
                'type_label'     => 'Documento de Lead',
                'original_name'  => $d->original_name,
                'size_bytes'     => (int) $d->size_bytes,
                'mime_type'      => $d->mime_type,
                'created_at'     => optional($d->created_at)->toIso8601String(),
                'uploader'       => $d->uploader?->name,
                'context_label'  => $d->lead?->name ? 'Lead: ' . $d->lead->name : 'Lead #' . $d->lead_id,
                'context_url'    => $d->lead_id ? "lead.html?id={$d->lead_id}" : null,
                'download_url'   => "/leads/{$d->lead_id}/documents/{$d->id}/download",
            ]);
    }

    private function fromCustomFieldFiles(): Collection
    {

        return LeadCustomFieldValue::query()
            ->with(['lead:id,name', 'customField:id,name,slug,type'])

            ->whereHas('lead')
            ->whereHas('customField', fn ($q) => $q->where('type', 'file'))
            ->whereNotNull('value')
            ->get()
            ->map(function ($v) {
                $meta = json_decode((string) $v->value, true);
                if (!is_array($meta) || empty($meta['path'])) return null;
                return [
                    'id'             => 'cf-' . $v->id,
                    'type'           => 'custom_field',
                    'type_label'     => 'Campo personalizado: ' . ($v->customField?->name ?? '—'),
                    'original_name'  => $meta['name'] ?? basename($meta['path']),
                    'size_bytes'     => (int) ($meta['size'] ?? 0),
                    'mime_type'      => $meta['mime'] ?? null,
                    'created_at'     => optional($v->updated_at ?: $v->created_at)->toIso8601String(),
                    'uploader'       => null,
                    'context_label'  => $v->lead?->name ? 'Lead: ' . $v->lead->name : 'Lead #' . $v->lead_id,
                    'context_url'    => $v->lead_id ? "lead.html?id={$v->lead_id}" : null,
                    'download_url'   => $v->customField?->slug
                        ? "/leads/{$v->lead_id}/custom-field-files/" . urlencode($v->customField->slug)
                        : null,
                ];
            })
            ->filter()
            ->values();
    }

    private function fromChatAttachments(): Collection
    {

        return ChatMessageAttachment::query()
            ->with(['uploader:id,name'])
            ->where('type', 'upload')
            ->whereNotNull('storage_path')
            ->get()
            ->map(fn ($a) => [
                'id'             => 'chat-' . $a->id,
                'type'           => 'chat',
                'type_label'     => 'Anexo de Chat',
                'original_name'  => $a->original_name,
                'size_bytes'     => (int) $a->size_bytes,
                'mime_type'      => $a->mime_type,
                'created_at'     => optional($a->created_at)->toIso8601String(),
                'uploader'       => $a->uploader?->name,
                'context_label'  => 'Chat (msg #' . $a->message_id . ')',
                'context_url'    => null,
                'download_url'   => "/chat/attachments/{$a->id}/download",
            ]);
    }

    private function fromEmpreendimentoDocs(): Collection
    {
        $rows = collect();

        $emps = Empreendimento::query()
            ->select(['id', 'name', 'book_path', 'book_uploaded_at', 'price_table_path', 'price_table_uploaded_at'])
            ->where(function ($q) {
                $q->whereNotNull('book_path')->orWhereNotNull('price_table_path');
            })
            ->get();

        foreach ($emps as $e) {
            if ($e->book_path) {
                $rows->push([
                    'id'             => 'emp-book-' . $e->id,
                    'type'           => 'empreendimento',
                    'type_label'     => 'Book do Empreendimento',
                    'original_name'  => basename($e->book_path),
                    'size_bytes'     => $this->fileSize($e->book_path),
                    'mime_type'      => $this->mimeFromPath($e->book_path),
                    'created_at'     => optional($e->book_uploaded_at)->toIso8601String(),
                    'uploader'       => null,
                    'context_label'  => 'Empreendimento: ' . $e->name,
                    'context_url'    => "empreendimento.html?id={$e->id}",
                    'download_url'   => "/empreendimentos/{$e->id}/documents/book/download",
                ]);
            }
            if ($e->price_table_path) {
                $rows->push([
                    'id'             => 'emp-price-' . $e->id,
                    'type'           => 'empreendimento',
                    'type_label'     => 'Tabela de Valores',
                    'original_name'  => basename($e->price_table_path),
                    'size_bytes'     => $this->fileSize($e->price_table_path),
                    'mime_type'      => $this->mimeFromPath($e->price_table_path),
                    'created_at'     => optional($e->price_table_uploaded_at)->toIso8601String(),
                    'uploader'       => null,
                    'context_label'  => 'Empreendimento: ' . $e->name,
                    'context_url'    => "empreendimento.html?id={$e->id}",
                    'download_url'   => "/empreendimentos/{$e->id}/documents/price_table/download",
                ]);
            }
        }

        return $rows;
    }

    private function fromEmpreendimentoImages(): Collection
    {
        return EmpreendimentoImage::query()
            ->with(['empreendimento:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($i) => [
                'id'             => 'empimg-' . $i->id,
                'type'           => 'empreendimento_image',
                'type_label'     => 'Imagem do Empreendimento (' . ($i->category ?: 'imagens') . ')',
                'original_name'  => basename($i->image_path),
                'size_bytes'     => $this->fileSize($i->image_path),
                'mime_type'      => $this->mimeFromPath($i->image_path),
                'created_at'     => optional($i->created_at)->toIso8601String(),
                'uploader'       => null,
                'context_label'  => 'Empreendimento: ' . ($i->empreendimento?->name ?? '#' . $i->empreendimento_id),
                'context_url'    => $i->empreendimento_id ? "empreendimento.html?id={$i->empreendimento_id}" : null,
                'download_url'   => null,
            ]);
    }

    private function fromUserAvatars(): Collection
    {
        return User::query()
            ->whereNotNull('avatar')
            ->where('avatar', '!=', '')
            ->select(['id', 'name', 'avatar', 'created_at', 'updated_at'])
            ->get()
            ->map(fn ($u) => [
                'id'             => 'avatar-' . $u->id,
                'type'           => 'user_avatar',
                'type_label'     => 'Foto de Perfil',
                'original_name'  => basename($u->avatar),
                'size_bytes'     => $this->fileSize($u->avatar),
                'mime_type'      => $this->mimeFromPath($u->avatar),
                'created_at'     => optional($u->updated_at ?: $u->created_at)->toIso8601String(),
                'uploader'       => $u->name,
                'context_label'  => 'Usuário: ' . $u->name,
                'context_url'    => "corretor.html?id={$u->id}",
                'download_url'   => null,
            ]);
    }

    private function fromCommissionReceipts(): Collection
    {
        return Commission::query()
            ->whereNotNull('payment_receipt_path')
            ->where('payment_receipt_path', '!=', '')
            ->with(['corretor:id,name', 'lead:id,name'])
            ->get()
            ->map(fn ($c) => [
                'id'             => 'commission-' . $c->id,
                'type'           => 'commission_receipt',
                'type_label'     => 'Comprovante de Comissão',
                'original_name'  => basename($c->payment_receipt_path),
                'size_bytes'     => $this->fileSize($c->payment_receipt_path),
                'mime_type'      => $this->mimeFromPath($c->payment_receipt_path),
                'created_at'     => optional($c->paid_at ?: $c->updated_at ?: $c->created_at)->toIso8601String(),
                'uploader'       => $c->corretor?->name,
                'context_label'  => 'Comissão #' . $c->id . ($c->lead?->name ? ' · Lead: ' . $c->lead->name : ''),
                'context_url'    => 'comissoes.html',
                'download_url'   => null,
            ]);
    }

    private function safeCollect(string $sourceName, callable $fn): Collection
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            \Log::warning("[AdminFilesController] fonte '{$sourceName}' falhou: " . $e->getMessage());
            return collect();
        }
    }

    private function fileSize(?string $path): int
    {
        if (!$path) return 0;
        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return (int) Storage::disk($disk)->size($path);
                }
            } catch (\Throwable $e) {

            }
        }
        return 0;
    }

    private function mimeFromPath(?string $path): ?string
    {
        if (!$path) return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf'  => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
            default => null,
        };
    }
}
