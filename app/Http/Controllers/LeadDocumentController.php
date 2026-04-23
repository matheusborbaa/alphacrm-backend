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

/**
 * Documentos anexados a um lead.
 *
 * Storage: disco 'local' (storage/app/private/ no Laravel 11), prefixo
 * 'leads/{leadId}/'. Nenhum arquivo é servido estaticamente — sempre passa
 * pelo @download que checa permissão antes de streamear. Isso evita URL
 * pública acidental e crawler indexando conteúdo sensível.
 *
 * Fluxo de exclusão (produto, configurável em Settings):
 *
 *   - doc_deletion_requires_approval = true  (default):
 *       corretor/gestor SOLICITAM -> admin APROVA -> soft-delete ->
 *       janela de retenção (doc_retention_days) -> purge pelo job.
 *
 *   - doc_deletion_requires_approval = false:
 *       corretor/gestor clicam "excluir" -> vai direto pra soft-delete
 *       -> janela de retenção -> purge pelo job.
 *
 * Em ambos os casos o doc passa pela "lixeira" por N dias antes de sumir
 * de vez; admin pode Restaurar nesse período.
 *
 * Auditoria:
 *   - Cada upload/request/approve/reject/restore gera LeadHistory.
 *   - Cada download gera uma row em lead_document_accesses com IP + geo.
 *
 * Permissões:
 *   - listar/upload/download/solicitar exclusão: quem pode ver o lead.
 *   - cancelar a PRÓPRIA solicitação: o solicitante.
 *   - aprovar/rejeitar, restaurar, expurgar agora, ver access log: só admin.
 */
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

    /* ==============================================================
     * LIST
     * ============================================================== */
    public function index(Lead $lead)
    {
        $this->authorize('view', $lead);

        // Inclui docs em "retenção" (deleted_at setado mas purge_at no futuro)
        // pra UI renderizar riscado com badge "será excluído em X dias".
        // NÃO inclui docs já expurgados (row inexistente).
        $docs = LeadDocument::with(['uploader:id,name', 'deletionRequester:id,name'])
            ->where('lead_id', $lead->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => $this->present($d));

        return response()->json($docs);
    }

    /* ==============================================================
     * PENDING DELETIONS (CROSS-LEAD)
     * ==============================================================
     * Só solicitações PENDENTES de aprovação (ainda não soft-deleted).
     * Docs em retenção não aparecem aqui — o admin vê eles direto no lead.
     */
    public function pendingDeletions(Request $request)
    {
        $this->ensureAdmin();

        $docs = LeadDocument::with([
                'uploader:id,name',
                'deletionRequester:id,name',
                'lead:id,name',
            ])
            ->whereNotNull('deletion_requested_at')
            ->whereNull('deleted_at') // ← só os que AINDA precisam de aprovação
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

    /* ==============================================================
     * UPLOAD
     * ============================================================== */
    public function store(Request $request, Lead $lead)
    {
        $this->authorize('view', $lead);

        // ⚠️ Guard contra post_max_size estourado no PHP.
        // Quando o payload passa do post_max_size do php.ini, o PHP descarta
        // $_POST e $_FILES silenciosamente — só sobra Content-Length no header.
        // Sem esse check, o validate() abaixo reclama de "The file field is
        // required" e o usuário vê uma mensagem sem sentido, porque ele sabe
        // que selecionou o arquivo. Detectamos aqui e devolvemos 413 com
        // mensagem clara, apontando o limite.
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
            // Mensagens PT-BR amigáveis pros erros mais comuns do uploader.
            $errors = $e->errors();
            $first  = collect($errors)->flatten()->first() ?: 'Dados inválidos.';
            // Se o erro é "file required" mas o upload veio com tamanho > 0,
            // quase sempre é upload_max_filesize estourado (o PHP descarta o
            // arquivo individualmente mas mantém $_POST). Mostra mensagem clara.
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

        // Safety-net: em cenários raros (erros de I/O do upload) o PHP marca
        // o file com isValid() false. O validate() já pega isso quase sempre,
        // mas tratamos aqui pra nunca chamar getMimeType() num arquivo inválido.
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

    /* ==============================================================
     * DOWNLOAD
     * ==============================================================
     * - Permite download de docs em retenção (admin/gestor precisam poder
     *   revisar antes de decidir restaurar/expurgar).
     * - CORRETOR fica bloqueado assim que existe solicitação de exclusão
     *   (pendente OU em retenção) — só admin/gestor podem visualizar o
     *   documento depois da solicitação. Isso atende LGPD: corretor solicita,
     *   gestor/admin auditam.
     * - Registra cada acesso em lead_document_accesses com IP + geo.
     */
    public function download(Request $request, Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        // Bloqueio LGPD: se o doc tem solicitação de exclusão (pendente
        // ou já em retenção), corretor não pode mais baixar. Só admin/gestor.
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

        // Log do acesso. Qualquer erro aqui não pode atrapalhar o download —
        // logAccess é try/catch-isolada internamente.
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

    /**
     * Preview inline — serve o arquivo com Content-Disposition: inline pra
     * exibir direto no navegador (via iframe/img no blob URL do frontend).
     *
     * Mesmas regras do download (auth, LGPD, lixeira), mas loga em
     * lead_document_accesses com action='preview' pra separar métrica de
     * visualização de download efetivo.
     */
    public function preview(Request $request, Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        // Mesmo bloqueio LGPD do download: doc com solicitação em aberto ou
        // em retenção só abre pra admin/gestor.
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

        // Log do acesso com action='preview' (distingue de download pro audit log).
        $this->logAccess($request, $lead, $document, 'preview');

        $mime = $document->mime_type ?: 'application/octet-stream';
        $size = (int) $document->size_bytes;

        // Sanitiza nome do arquivo pro header (evita quebra de linha / aspas).
        $safeName = str_replace(['"', "\r", "\n"], ['\\"', '', ''], (string) $document->original_name);

        $headers = [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $safeName . '"',
            'Cache-Control'       => 'private, no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
            // Permite embed via iframe/img no mesmo domínio do frontend.
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

    /* ==============================================================
     * DELETION WORKFLOW
     * ============================================================== */

    /**
     * Solicita exclusão.
     *
     *   - Se doc_deletion_requires_approval = true: marca como pendente e
     *     aguarda admin aprovar.
     *   - Se false: vai DIRETO pra soft-delete (pula etapa de aprovação).
     */
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
            // Modo padrão: marca pendente pra admin aprovar
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
            // Modo "sem aprovação": vai direto pra retenção (lixeira).
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

    /**
     * Admin aprova exclusão.
     *
     * NÃO é mais hard-delete: move pra "lixeira" (soft-delete) e programa
     * o purge pra now() + doc_retention_days. Admin ainda pode restaurar
     * dentro dessa janela.
     */
    public function approveDeletion(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        if (!$document->isDeletionPending()) {
            return response()->json(['message' => 'Nenhuma solicitação pendente pra aprovar.'], 409);
        }

        // Solicitante fica registrado como responsável pela solicitação original;
        // não sobrescrevemos os campos deletion_* aqui — eles viram contexto
        // histórico do doc em retenção.
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

    /**
     * Admin restaura um doc que está aguardando expurgo (dentro da janela).
     * Zera deleted_at/purge_at e limpa o estado de solicitação de exclusão.
     */
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

    /**
     * Admin força o expurgo imediato de um doc em retenção (ou até um
     * doc ativo, a pedido). Faz o hard-delete que o job faria amanhã.
     */
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

    /* ==============================================================
     * ACCESS LOG (admin only)
     * ============================================================== */

    /** Lista os acessos/downloads de um documento. Só admin. */
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

    /* ==============================================================
     * HELPERS
     * ============================================================== */

    private function ensureDocBelongsToLead(Lead $lead, LeadDocument $document): void
    {
        if ((int) $document->lead_id !== (int) $lead->id) {
            abort(404);
        }
    }

    /**
     * Converte valor de ini (ex.: "15M", "1G", "8388608") em bytes.
     * Retorna 0 quando não consegue parsear.
     */
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

    /** Formata bytes em "15 MB" / "512 KB" pra mostrar na mensagem de erro. */
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
        return strtolower(trim((string) ($u->role ?? ''))) === 'admin';
    }

    /** Admin OU gestor (a "visão gerencial" que pode auditar documentos). */
    private function userIsAdminOrManager(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        $role = strtolower(trim((string) ($u->role ?? '')));
        return $role === 'admin' || $role === 'gestor';
    }

    private function ensureAdmin(): void
    {
        if (!$this->userIsAdmin()) {
            abort(403, 'Ação restrita ao administrador.');
        }
    }

    /** Dias de retenção (lê do settings, clampa em 1..365). */
    private function retentionDays(): int
    {
        $raw  = (int) Setting::get('doc_retention_days', 7);
        return max(1, min(365, $raw ?: 7));
    }

    /**
     * Marca o doc como soft-deleted: deleted_at = agora, purge_at = agora + N.
     * Se deletion_requested_by/at/reason vieram vazios, deixa como estão.
     */
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

        // Preserva contexto da solicitação se veio do fluxo "sem aprovação",
        // onde esses campos ainda não foram preenchidos.
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

    /**
     * Registra um acesso ao documento (download/preview). Falhas aqui são
     * silenciosas — nunca podem impedir o download em si.
     */
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

    /** Serializa o doc pra JSON consumido pelo frontend. */
    private function present(LeadDocument $d): array
    {
        $pendingPurge = $d->isPendingPurge();
        $hasDeletion  = $d->isDeletionPending() || $pendingPurge;

        // Espelha a regra do @download: com solicitação em aberto, corretor
        // perde o botão de baixar. Admin/gestor mantêm acesso pra auditoria.
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

            // Pendente de aprovação (ainda no fluxo "request -> approve")
            'deletion' => $d->isDeletionPending() ? [
                'requested_by' => $d->relationLoaded('deletionRequester') && $d->deletionRequester ? [
                    'id'   => $d->deletionRequester->id,
                    'name' => $d->deletionRequester->name,
                ] : null,
                'requested_at' => $d->deletion_requested_at?->toIso8601String(),
                'reason'       => $d->deletion_reason,
            ] : null,

            // Aguardando expurgo (soft-deleted, janela de retenção aberta).
            // Frontend usa isso pra renderizar riscado + badge "será excluído em X dias".
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

    /* ==============================================================
     * ACCESS LOG — GLOBAL (admin only)
     * ==============================================================
     * Lista TODOS os acessos a documentos do CRM (paginado), com filtros
     * opcionais. Alimenta a tela "Configurações → Logs de Download".
     *
     * Filtros (query string):
     *   - lead_id           int    filtra por lead
     *   - document_id       int    filtra por doc específico
     *   - user_id           int    filtra por quem baixou
     *   - action            string 'download' (default) ou 'preview'
     *   - from / to         date   YYYY-MM-DD (inclusive)
     *   - q                 string busca por IP / país / cidade / ISP
     *   - per_page          int    default 50, max 200
     */
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
