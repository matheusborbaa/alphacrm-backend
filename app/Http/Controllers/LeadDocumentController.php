<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadHistory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos anexados a um lead.
 *
 * Storage: disco 'local' (storage/app/), prefixo 'private/leads/{leadId}/'.
 * Nenhum arquivo é servido estaticamente — sempre passa por @download que
 * checa permissão antes de streamear. Isso evita que alguém compartilhe
 * uma URL pública por acidente e que crawler indexe documento sensível.
 *
 * Fluxo de exclusão (produto): corretor/gestor SOLICITAM; só admin
 * efetiva. Colunas deletion_requested_* na tabela. Cada ação gera um
 * LeadHistory pra trilha de auditoria.
 *
 * Permissões:
 *  - listar / upload / download: quem pode VER o lead (LeadPolicy@view).
 *  - solicitar exclusão: quem pode ver.
 *  - cancelar a PRÓPRIA solicitação: o solicitante.
 *  - aprovar/rejeitar exclusão + delete direto: só role 'admin'.
 */
class LeadDocumentController extends Controller
{
    use AuthorizesRequests;

    /** Limite do upload e whitelist de MIMEs. Valor conservador de 15 MB. */
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

    private const ALLOWED_MIMES = [
        // Documentos
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/rtf',
        'text/plain',
        'text/csv',
        // Imagens
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
     * Lista GLOBAL de solicitações de exclusão pendentes — alimenta a
     * página /solicitacoes-exclusao.php. Só admin tem acesso porque só
     * admin pode aprovar/rejeitar mesmo (gestor/corretor já veem a
     * solicitação dentro do próprio lead).
     *
     * Retorna o doc + dados mínimos do lead pra navegação.
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
            ->orderBy('deletion_requested_at', 'asc') // mais antiga primeiro
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

        $request->validate([
            'file'        => 'required|file|max:' . (self::MAX_UPLOAD_BYTES / 1024), // KB
            'category'    => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'message' => 'Tipo de arquivo não permitido.',
                'mime'    => $mime,
            ], 422);
        }

        // Nome no disco: uuid + extensão original (evita colisão e injeção
        // de path via filename). Original_name continua na tabela pra UI.
        $ext      = $file->getClientOriginalExtension();
        $diskName = (string) Str::uuid() . ($ext ? '.' . strtolower($ext) : '');

        // IMPORTANTE: no Laravel 11, o disk 'local' tem root em
        // storage/app/private/. Por isso o prefixo do path relativo é
        // só 'leads/{id}' — o 'private/' NÃO entra, senão duplicaria a pasta.
        // Usamos o retorno do putFileAs como storage_path canônico pra garantir
        // que exists()/readStream() usem o mesmíssimo valor na hora do download.
        $storagePath = Storage::disk('local')->putFileAs(
            "leads/{$lead->id}",
            $file,
            $diskName
        );

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
     * Usa streamDownload (fpassthru) em vez de Storage::download() pra
     * não depender de X-Sendfile/X-Accel-Redirect — alguns hosts não
     * respondem certo com os headers do sendFile do Symfony.
     *
     * Fallback de path: docs antigos foram salvos com 'private/' hardcoded
     * no início do storage_path (bug da v1). A gente tenta o valor do banco
     * primeiro; se não existir, tenta sem o prefixo 'private/'.
     */
    public function download(Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        $disk = Storage::disk('local');
        $path = $this->resolveStoragePath($disk, $document->storage_path);

        if ($path === null) {
            abort(404, 'Arquivo não encontrado no storage.');
        }

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
            if ($stream === null || $stream === false) {
                // Já passou pelo exists(); se chegou aqui e falhou, aborta
                // silenciosamente pro browser — o navegador mostra "arquivo corrompido".
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $document->original_name, $headers);
    }

    /**
     * Tenta localizar o arquivo no disk. Se o path gravado tem 'private/'
     * como prefixo (docs antigos), retorna o path sem o prefixo quando ele
     * não existe com o prefixo. Null = arquivo não encontrado em nenhum lugar.
     */
    private function resolveStoragePath($disk, string $stored): ?string
    {
        if ($disk->exists($stored)) return $stored;

        // Compat: docs gravados na v1 tinham 'private/' hardcoded no path,
        // mas o disk 'local' no Laravel 11 já aponta pra storage/app/private.
        if (str_starts_with($stored, 'private/')) {
            $fallback = substr($stored, 8);
            if ($disk->exists($fallback)) return $fallback;
        }

        return null;
    }

    /* ==============================================================
     * DELETION WORKFLOW
     * ============================================================== */

    /** Qualquer usuário com acesso ao lead pode solicitar exclusão. */
    public function requestDeletion(Request $request, Lead $lead, LeadDocument $document)
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if ($document->isDeletionPending()) {
            return response()->json(['message' => 'Já existe uma solicitação de exclusão pendente.'], 409);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

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

        return response()->json($this->present($document->fresh(['uploader:id,name', 'deletionRequester:id,name'])));
    }

    /** Só o solicitante (ou admin) pode cancelar uma solicitação pendente. */
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

    /** Admin aprova a exclusão: remove row + arquivo no disco. */
    public function approveDeletion(Lead $lead, LeadDocument $document)
    {
        $this->ensureAdmin();
        $this->ensureDocBelongsToLead($lead, $document);

        if (!$document->isDeletionPending()) {
            return response()->json(['message' => 'Nenhuma solicitação pendente pra aprovar.'], 409);
        }

        $name = $document->original_name;

        // Remove o arquivo do disco (se existir) e a row. Hard delete porque
        // LGPD pede apagar, e o LeadHistory mantém o rastro.
        // Usa resolveStoragePath pro mesmo fallback do download (docs antigos).
        $disk = Storage::disk('local');
        $real = $this->resolveStoragePath($disk, $document->storage_path);
        if ($real !== null) $disk->delete($real);
        $document->delete();

        $this->logHistory($lead, 'document_deleted', $name);

        return response()->json(['ok' => true]);
    }

    /** Admin rejeita: limpa a solicitação, arquivo permanece. */
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

    /* ==============================================================
     * HELPERS
     * ============================================================== */

    private function ensureDocBelongsToLead(Lead $lead, LeadDocument $document): void
    {
        if ((int) $document->lead_id !== (int) $lead->id) {
            abort(404);
        }
    }

    private function userIsAdmin(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        return strtolower(trim((string) ($u->role ?? ''))) === 'admin';
    }

    private function ensureAdmin(): void
    {
        if (!$this->userIsAdmin()) {
            abort(403, 'Ação restrita ao administrador.');
        }
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

    /** Serializa o documento pra JSON consumido pelo frontend. */
    private function present(LeadDocument $d): array
    {
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
            'created_at'     => $d->created_at?->toIso8601String(),
            'deletion' => $d->isDeletionPending() ? [
                'requested_by' => $d->relationLoaded('deletionRequester') && $d->deletionRequester ? [
                    'id'   => $d->deletionRequester->id,
                    'name' => $d->deletionRequester->name,
                ] : null,
                'requested_at' => $d->deletion_requested_at?->toIso8601String(),
                'reason'       => $d->deletion_reason,
            ] : null,
        ];
    }
}
