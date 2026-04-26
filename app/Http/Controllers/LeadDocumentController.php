<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentAccess;
use App\Models\LeadHistory;
use App\Models\Setting;
use App\Services\GeoIpService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadDocumentController extends Controller
{
    use AuthorizesRequests;

    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

    private const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/rtf',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    public function index(Lead $lead)
    {
        $this->authorize('view', $lead);

        $native = LeadDocument::with(['uploader:id,name', 'deletionRequester:id,name'])
            ->where('lead_id', $lead->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($d) {
                $row = $this->present($d);
                $row['source']       = 'lead_doc';
                $row['source_label'] = 'Documento do lead';
                return $row;
            })
            ->all();

        $extras = array_merge(
            $this->aggregateMediaFiles($lead->id),
            $this->aggregateCustomFieldFiles($lead->id)
        );

        $all = array_merge($native, $extras);
        usort($all, fn ($a, $b) =>
            strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
        );

        return response()->json($all);
    }

    private function aggregateMediaFiles(int $leadId): array
    {
        try {
            $folder = \App\Models\MediaFolder::where('lead_id', $leadId)->first();
            if (!$folder) return [];

            return \App\Models\MediaFile::query()
                ->where('folder_id', $folder->id)
                ->with('uploader:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($f) => [
                    'id'              => 'media_' . $f->id,
                    'original_name'   => $f->original_name ?? $f->name,
                    'mime_type'       => $f->mime_type,
                    'size_bytes'      => (int) $f->size_bytes,
                    'category'        => $f->category,
                    'description'     => $f->description,
                    'uploader'        => $f->uploader ? [
                        'id' => $f->uploader->id, 'name' => $f->uploader->name,
                    ] : null,
                    'created_at'      => optional($f->created_at)->toIso8601String(),
                    'download_blocked' => false,
                    'deletion'        => null,
                    'pending_purge'   => null,

                    'download_url'    => "/media/files/{$f->id}/download",
                    'source'          => 'media',
                    'source_label'    => 'Biblioteca',
                ])
                ->all();
        } catch (\Throwable $e) {
            \Log::warning('Falha ao agregar media_files na aba Documentos', [
                'lead_id' => $leadId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function aggregateCustomFieldFiles(int $leadId): array
    {
        try {
            $rows = \App\Models\LeadCustomFieldValue::query()
                ->where('lead_id', $leadId)
                ->whereNotNull('value')
                ->with('customField:id,slug,name,type')
                ->get()
                ->filter(fn ($v) => optional($v->customField)->type === 'file');

            $out = [];
            foreach ($rows as $v) {
                $meta = is_string($v->value) ? json_decode($v->value, true) : (array) $v->value;
                if (!is_array($meta) || empty($meta['path'])) continue;

                $cfName = $v->customField->name ?? 'Campo customizado';
                $slug   = $v->customField->slug ?? '';

                $out[] = [
                    'id'              => 'cf_' . $v->id,
                    'original_name'   => $meta['name'] ?? 'Arquivo',
                    'mime_type'       => $meta['mime'] ?? null,
                    'size_bytes'      => (int) ($meta['size'] ?? 0),
                    'category'        => $cfName,
                    'description'     => 'Campo customizado: ' . $cfName,
                    'uploader'        => null,
                    'created_at'      => optional($v->updated_at)->toIso8601String(),
                    'download_blocked' => false,
                    'deletion'        => null,
                    'pending_purge'   => null,
                    'download_url'    => "/leads/{$leadId}/custom-field-files/{$slug}",
                    'source'          => 'custom_field',
                    'source_label'    => 'Campo personalizado',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            \Log::warning('Falha ao agregar custom field files na aba Documentos', [
                'lead_id' => $leadId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function pendingDeletions(Request $request)
    {
        $this->ensureAdmin();

        $docs = LeadDocument::with([
                'uploader:id,name',
                'deletionRequester:id,name',
                'lead:id,name',
            ])

            ->whereHas('lead')
            ->whereNotNull('deletion_requested_at')
            ->whereNull('deleted_at')
            ->orderBy('deletion_requested_at', 'asc')
            ->get()
            ->map(function ($d) {
                $base = $this->present($d);
                $base['lead'] = $d->relationLoaded('lead') && $d->lead ? [
                    'id'   => $d->lead->id,
                    'name' => $d->lead->name,
                ] : null;
                return $base;
            });

        return response()->json($docs);
    }

    public function store(Request $request, Lead $lead)
    {
        $this->authorize('view', $lead);

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMax       = $this->iniBytes(ini_get('post_max_size'));
        if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax
            && empty($_POST) && empty($_FILES)) {
            return response()->json([
                'message' => 'Arquivo muito grande para o servidor. Limite atual: '
                    . $this->formatBytes($postMax) . '.',
            ], 413);
        }

        try {
            $request->validate([
                'file'        => 'required|file|max:' . (self::MAX_UPLOAD_BYTES / 1024),
                'category'    => 'nullable|string|max:50',
                'description' => 'nullable|string|max:500',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            $errors = $e->errors();
            $first  = collect($errors)->flatten()->first() ?: 'Dados inválidos.';

            if (($errors['file'][0] ?? null)
                && str_contains(strtolower($errors['file'][0]), 'required')
                && $contentLength > 0) {
                $uploadMax = $this->iniBytes(ini_get('upload_max_filesize'));
                return response()->json([
                    'message' => 'Arquivo muito grande para o servidor. Limite atual por arquivo: '
                        . $this->formatBytes($uploadMax ?: $postMax) . '.',
                ], 413);
            }
            return response()->json([
                'message' => $first,
                'errors'  => $errors,
            ], 422);
        }

        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize do servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede MAX_FILE_SIZE do formulário.',
                UPLOAD_ERR_PARTIAL    => 'Upload foi interrompido. Tente novamente.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Servidor sem diretório temporário configurado.',
                UPLOAD_ERR_CANT_WRITE => 'Servidor não conseguiu gravar o arquivo em disco.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão do PHP.',
            ];
            $code = $file ? $file->getError() : UPLOAD_ERR_NO_FILE;
            return response()->json([
                'message' => $errMap[$code] ?? 'Falha no upload do arquivo.',
            ], 422);
        }

        $mime = $file->getMimeType();

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'message' => 'Tipo de arquivo não permitido (' . $mime . ').',
                'mime'    => $mime,
            ], 422);
        }

        $ext      = $file->getClientOriginalExtension();
        $diskName = (string) Str::uuid() . ($ext ? '.' . strtolower($ext) : '');

        try {
            $storagePath = Storage::disk('local')->putFileAs(
                "leads/{$lead->id}",
                $file,
                $diskName
            );
        } catch (\Throwable $e) {
            Log::error('LeadDocument upload: falha ao gravar no storage', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Falha ao gravar o arquivo no servidor. Verifique permissões do diretório storage/.',
            ], 500);
        }

        if (!$storagePath) {
            return response()->json([
                'message' => 'Falha ao gravar o arquivo no storage.',
            ], 500);
        }

        $doc = LeadDocument::create([
            'lead_id'          => $lead->id,
            'uploader_user_id' => auth()->id(),
            'original_name'    => mb_substr($file->getClientOriginalName(), 0, 255),
            'storage_path'     => $storagePath,
            'mime_type'        => $mime,
            'size_bytes'       => $file->getSize() ?: 0,
            'category'         => $request->input('category'),
            'description'      => $request->input('description'),
        ]);

        $this->logHistory($lead, 'document_upload', $doc->original_name);

        return response()->json($this->present($doc->fresh(['uploader:id,name'])), 201);
    }

    public function download(Request $request, Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if (
            ($document->isDeletionPending() || $document->deleted_at !== null)
            && !$this->userIsAdminOrManager()
        ) {
            abort(403, 'Documento indisponível: há uma solicitação de exclusão em aberto. Apenas admin/gestor podem acessar.');
        }

        $disk = Storage::disk('local');
        $path = $this->resolveStoragePath($disk, $document->storage_path);

        if ($path === null) {
            abort(404, 'Arquivo não encontrado no storage.');
        }

        $this->logAccess($request, $lead, $document, 'download');

        $mime = $document->mime_type ?: 'application/octet-stream';
        $size = (int) $document->size_bytes;

        $headers = [
            'Content-Type'        => $mime,
            'Content-Description' => 'File Transfer',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
        ];
        if ($size > 0) $headers['Content-Length'] = (string) $size;

        return response()->streamDownload(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream === null || $stream === false) return;
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $document->original_name, $headers);
    }

    public function preview(Request $request, Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if (
            ($document->isDeletionPending() || $document->deleted_at !== null)
            && !$this->userIsAdminOrManager()
        ) {
            abort(403, 'Documento indisponível: há uma solicitação de exclusão em aberto. Apenas admin/gestor podem acessar.');
        }

        $disk = Storage::disk('local');
        $path = $this->resolveStoragePath($disk, $document->storage_path);

        if ($path === null) {
            abort(404, 'Arquivo não encontrado no storage.');
        }

        $this->logAccess($request, $lead, $document, 'preview');

        $mime = $document->mime_type ?: 'application/octet-stream';
        $size = (int) $document->size_bytes;

        $safeName = str_replace(['"', "\r", "\n"], ['\\"', '', ''], (string) $document->original_name);

        $headers = [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $safeName . '"',
            'Cache-Control'       => 'private, no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',

            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($size > 0) $headers['Content-Length'] = (string) $size;

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream === null || $stream === false) return;
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, 200, $headers);
    }

    private function resolveStoragePath($disk, string $stored): ?string
    {
        if ($disk->exists($stored)) return $stored;
        if (str_starts_with($stored, 'private/')) {
            $fallback = substr($stored, 8);
            if ($disk->exists($fallback)) return $fallback;
        }
        return null;
    }

    public function requestDeletion(Request $request, Lead $lead, LeadDocument $document)
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if ($document->deleted_at !== null) {
            return response()->json(['message' => 'Documento já está aguardando expurgo.'], 409);
        }
        if ($document->isDeletionPending()) {
            return response()->json(['message' => 'Já existe uma solicitação de exclusão pendente.'], 409);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $requiresApproval = (bool) Setting::get('doc_deletion_requires_approval', true);

        if ($requiresApproval) {

            $document->update([
                'deletion_requested_by' => auth()->id(),
                'deletion_requested_at' => now(),
                'deletion_reason'       => $data['reason'] ?? null,
            ]);

            $this->logHistory(
                $lead,
                'document_deletion_requested',
                $document->original_name . ($data['reason'] ?? '' ? ' — motivo: ' . $data['reason'] : '')
            );
        } else {

            $this->softDeleteWithRetention($document, auth()->id(), $data['reason'] ?? null);

            $this->logHistory(
                $lead,
                'document_deleted',
                $document->original_name . ' (sem aprovação — direto pra retenção)'
            );
        }

        return response()->json($this->present($document->fresh([
            'uploader:id,name', 'deletionRequester:id,name',
        ])));
    }

    public function cancelDeletionRequest(Lead $lead, LeadDocument $document)
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if (!$document->isDeletionPending()) {
            return response()->json(['message' => 'Nenhuma solicitação pendente.'], 409);
        }

        $isAdmin = $this->userIsAdmin();
        $isOwner = (int) $document->deletion_requested_by === (int) auth()->id();
        if (!$isAdmin && !$isOwner) {
            abort(403, 'Só o solicitante ou um admin pode cancelar.');
        }

        $document->update([
            'deletion_requested_by' => null,
            'deletion_requested_at' => null,
            'deletion_reason'       => null,
        ]);

        $this->logHistory($lead, 'document_deletion_cancelled', $document->original_name);

        return response()->json($this->present($document->fresh(['uploader:id,name'])));
    }

    public function approveDeletion(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        if (!$document->isDeletionPending()) {
            return response()->json(['message' => 'Nenhuma solicitação pendente pra aprovar.'], 409);
        }

        $this->softDeleteWithRetention(
            $document,
            $document->deletion_requested_by,
            $document->deletion_reason
        );

        $days = $this->retentionDays();
        $this->logHistory(
            $lead,
            'document_deleted',
            sprintf('%s — aprovado, purge em %d dias', $document->original_name, $days)
        );

        return response()->json($this->present($document->fresh([
            'uploader:id,name', 'deletionRequester:id,name',
        ])));
    }

    public function rejectDeletion(Request $request, Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        if (!$document->isDeletionPending()) {
            return response()->json(['message' => 'Nenhuma solicitação pendente.'], 409);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $document->update([
            'deletion_requested_by' => null,
            'deletion_requested_at' => null,
            'deletion_reason'       => null,
        ]);

        $this->logHistory(
            $lead,
            'document_deletion_rejected',
            $document->original_name . ($data['reason'] ?? '' ? ' — motivo: ' . $data['reason'] : '')
        );

        return response()->json($this->present($document->fresh(['uploader:id,name'])));
    }

    public function restore(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        if ($document->deleted_at === null) {
            return response()->json(['message' => 'Documento não está em retenção.'], 409);
        }

        $document->update([
            'deleted_at'            => null,
            'purge_at'              => null,
            'deletion_requested_by' => null,
            'deletion_requested_at' => null,
            'deletion_reason'       => null,
        ]);

        $this->logHistory($lead, 'document_restored', $document->original_name);

        return response()->json($this->present($document->fresh([
            'uploader:id,name', 'deletionRequester:id,name',
        ])));
    }

    public function forcePurge(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        $name = $document->original_name;

        $disk = Storage::disk('local');
        $real = $this->resolveStoragePath($disk, $document->storage_path);
        if ($real !== null) $disk->delete($real);
        $document->delete();

        $this->logHistory($lead, 'document_purged', $name . ' (expurgo forçado pelo admin)');

        return response()->json(['ok' => true]);
    }

    public function accesses(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        $rows = LeadDocumentAccess::with('user:id,name')
            ->where('lead_document_id', $document->id)
            ->orderByDesc('accessed_at')
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'id'           => $a->id,
                'action'       => $a->action,
                'user'         => $a->relationLoaded('user') && $a->user ? [
                    'id'   => $a->user->id,
                    'name' => $a->user->name,
                ] : null,
                'ip_address'   => $a->ip_address,
                'user_agent'   => $a->user_agent,
                'country'      => $a->country,
                'country_code' => $a->country_code,
                'region'       => $a->region,
                'city'         => $a->city,
                'isp'          => $a->isp,
                'lat'          => $a->lat !== null ? (float) $a->lat : null,
                'lon'          => $a->lon !== null ? (float) $a->lon : null,
                'accessed_at'  => $a->accessed_at?->toIso8601String(),
            ]);

        return response()->json($rows);
    }

    private function ensureDocBelongsToLead(Lead $lead, LeadDocument $document): void
    {
        if ((int) $document->lead_id !== (int) $lead->id) {
            abort(404);
        }
    }

    private function iniBytes(?string $val): int
    {
        if ($val === null || $val === '') return 0;
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $num  = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return (($n == (int) $n) ? (string) (int) $n : number_format($n, 1, '.', ''))
            . ' ' . $units[$i];
    }

    private function userIsAdmin(): bool
    {
        $u = auth()->user();
        if (!$u) return false;

        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        return $role === 'admin';
    }

    private function userIsAdminOrManager(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        return $role === 'admin' || $role === 'gestor';
    }

    private function ensureAdmin(): void
    {
        if (!$this->userIsAdmin()) {
            abort(403, 'Ação restrita ao administrador.');
        }
    }

    private function retentionDays(): int
    {
        $raw  = (int) Setting::get('doc_retention_days', 7);
        return max(1, min(365, $raw ?: 7));
    }

    private function softDeleteWithRetention(
        LeadDocument $document,
        ?int $byUserId,
        ?string $reason
    ): void {
        $days = $this->retentionDays();

        $update = [
            'deleted_at' => now(),
            'purge_at'   => now()->addDays($days),
        ];

        if ($document->deletion_requested_at === null) {
            $update['deletion_requested_by'] = $byUserId;
            $update['deletion_requested_at'] = now();
            $update['deletion_reason']       = $reason;
        }

        $document->update($update);
    }

    private function logHistory(Lead $lead, string $type, string $description): void
    {
        $uid = auth()->check() ? auth()->id() : null;
        if (!$uid) return;

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => $uid,
            'type'        => $type,
            'description' => mb_substr($description, 0, 500),
        ]);
    }

    private function logAccess(
        Request $request,
        Lead $lead,
        LeadDocument $document,
        string $action = 'download'
    ): void {
        try {
            $ip = $request->ip();
            $ua = mb_substr((string) $request->userAgent(), 0, 500);
            $geo = GeoIpService::lookup($ip) ?? [];

            LeadDocumentAccess::create([
                'lead_document_id' => $document->id,
                'lead_id'          => $lead->id,
                'user_id'          => auth()->id(),
                'action'           => $action,
                'ip_address'       => $ip,
                'user_agent'       => $ua,
                'country'          => $geo['country']      ?? null,
                'country_code'     => $geo['country_code'] ?? null,
                'region'           => $geo['region']       ?? null,
                'city'             => $geo['city']         ?? null,
                'isp'              => $geo['isp']          ?? null,
                'lat'              => $geo['lat']          ?? null,
                'lon'              => $geo['lon']          ?? null,
                'accessed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('logAccess failed: ' . $e->getMessage(), [
                'doc' => $document->id,
            ]);
        }
    }

    private function present(LeadDocument $d): array
    {
        $pendingPurge = $d->isPendingPurge();
        $hasDeletion  = $d->isDeletionPending() || $pendingPurge;

        $downloadBlocked = $hasDeletion && !$this->userIsAdminOrManager();

        return [
            'id'             => $d->id,
            'original_name'  => $d->original_name,
            'mime_type'      => $d->mime_type,
            'size_bytes'     => (int) $d->size_bytes,
            'category'       => $d->category,
            'description'    => $d->description,
            'uploader'       => $d->relationLoaded('uploader') && $d->uploader ? [
                'id'   => $d->uploader->id,
                'name' => $d->uploader->name,
            ] : null,
            'created_at'      => $d->created_at?->toIso8601String(),
            'download_blocked' => $downloadBlocked,

            'deletion' => $d->isDeletionPending() ? [
                'requested_by' => $d->relationLoaded('deletionRequester') && $d->deletionRequester ? [
                    'id'   => $d->deletionRequester->id,
                    'name' => $d->deletionRequester->name,
                ] : null,
                'requested_at' => $d->deletion_requested_at?->toIso8601String(),
                'reason'       => $d->deletion_reason,
            ] : null,

            'pending_purge' => $pendingPurge ? [
                'deleted_at'       => $d->deleted_at?->toIso8601String(),
                'purge_at'         => $d->purge_at?->toIso8601String(),
                'days_until_purge' => $d->daysUntilPurge(),
                'reason'           => $d->deletion_reason,
                'deleted_by'       => $d->relationLoaded('deletionRequester') && $d->deletionRequester ? [
                    'id'   => $d->deletionRequester->id,
                    'name' => $d->deletionRequester->name,
                ] : null,
            ] : null,
        ];
    }

    public function allAccesses(Request $request)
    {
        $this->ensureAdmin();

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(10, min(200, $perPage));

        $q = LeadDocumentAccess::with([
                'user:id,name,email',
                'document:id,original_name,mime_type,lead_id',
                'lead:id,name',
            ])

            ->whereHas('lead')
            ->orderByDesc('accessed_at');

        if ($leadId = $request->input('lead_id')) {
            $q->where('lead_id', (int) $leadId);
        }
        if ($docId = $request->input('document_id')) {
            $q->where('lead_document_id', (int) $docId);
        }
        if ($userId = $request->input('user_id')) {
            $q->where('user_id', (int) $userId);
        }
        if ($action = $request->input('action')) {
            $q->where('action', $action);
        }
        if ($from = $request->input('from')) {
            try { $q->whereDate('accessed_at', '>=', $from); } catch (\Throwable $e) {}
        }
        if ($to = $request->input('to')) {
            try { $q->whereDate('accessed_at', '<=', $to); } catch (\Throwable $e) {}
        }
        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($sub) use ($search) {
                $like = '%' . $search . '%';
                $sub->where('ip_address', 'like', $like)
                    ->orWhere('country', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('region', 'like', $like)
                    ->orWhere('isp', 'like', $like);
            });
        }

        $page = $q->paginate($perPage);

        $rows = collect($page->items())->map(fn ($a) => [
            'id'           => $a->id,
            'action'       => $a->action,
            'accessed_at'  => $a->accessed_at?->toIso8601String(),
            'ip_address'   => $a->ip_address,
            'user_agent'   => $a->user_agent,
            'country'      => $a->country,
            'country_code' => $a->country_code,
            'region'       => $a->region,
            'city'         => $a->city,
            'isp'          => $a->isp,
            'lat'          => $a->lat !== null ? (float) $a->lat : null,
            'lon'          => $a->lon !== null ? (float) $a->lon : null,
            'user'         => $a->relationLoaded('user') && $a->user ? [
                'id'    => $a->user->id,
                'name'  => $a->user->name,
                'email' => $a->user->email,
            ] : null,
            'lead'         => $a->relationLoaded('lead') && $a->lead ? [
                'id'   => $a->lead->id,
                'name' => $a->lead->name,
            ] : null,
            'document'     => $a->relationLoaded('document') && $a->document ? [
                'id'            => $a->document->id,
                'original_name' => $a->document->original_name,
                'mime_type'     => $a->document->mime_type,
            ] : null,
        ]);

        return response()->json([
            'data'         => $rows,
            'current_page' => $page->currentPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'last_page'    => $page->lastPage(),
        ]);
    }
}
