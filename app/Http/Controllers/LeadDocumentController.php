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
        $ext         = $file->getClientOriginalExtension();
        $diskName    = (string) Str::uuid() . ($ext ? '.' . strtolower($ext) : '');
        $storagePath = "private/leads/{$lead->id}/{$diskName}";

        Storage::disk('local')->putFileAs(
            "private/leads/{$lead->id}",
            $file,
            $diskName
        );

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
     * ============================================================== */
    public function download(Lead $lead, LeadDocument $document): StreamedResponse
    {
        $this->authorize('view', $lead);
        $this->ensureDocBelongsToLead($lead, $document);

        if (!Storage::disk('local')->exists($document->storage_path)) {
            abort(404, 'Arquivo não encontrado no storage.');
        }

        return Storage::disk('local')->download(
            $document->storage_path,
            $document->original_name
        );
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
        Storage::disk('local')->delete($document->storage_path);
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
