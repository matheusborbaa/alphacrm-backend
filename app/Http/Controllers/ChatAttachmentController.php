<?php

namespace App\Http\Controllers;

use App\Models\ChatMessageAttachment;
use App\Models\LeadDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller de anexos do chat.
 *
 * Fluxo de upload (tipo 'upload'):
 *   1. Frontend abre modal de anexo, aba "Arquivo" → user seleciona arquivo
 *   2. POST /chat/attachments/upload (multipart) — sobe o arquivo agora,
 *      cria row em chat_message_attachments com message_id=NULL (draft)
 *   3. Resposta traz { id, preview_url, snapshot }
 *   4. Usuário pode anexar mais arquivos/referências, fica tudo pendente no
 *      frontend state.
 *   5. Ao enviar a msg, o POST /messages inclui "pending_upload_ids":[ids]
 *      — aí o ChatMessageController "adota" os drafts, setando message_id.
 *
 * Endpoints de referência (lead/empreendimento/lead_document) não passam
 * por aqui — ChatMessageController recebe o array e chama o resolver.
 * Esse controller cuida só de arquivo físico (upload + download/preview).
 *
 * Storage: disco 'local' privado (igual ao de lead_documents). Nunca expõe
 * path direto pro frontend — sempre passa pelo endpoint de download que
 * valida permissão (user precisa ser participante da conversa da msg).
 *
 * Tamanho máximo: 10 MB por upload. Tipos aceitos: imagens, PDF, Office docs.
 */
class ChatAttachmentController extends Controller
{
    private const MAX_SIZE_KB = 10240;       // 10 MB
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'application/zip',
    ];

    /**
     * Upload de arquivo "draft" — sem mensagem associada ainda. O row fica
     * em chat_message_attachments com message_id=NULL. O frontend passa o id
     * no store da mensagem depois.
     *
     * Limpeza de drafts abandonados: cron futuro pode purgar rows com
     * message_id NULL e created_at < now - 1h.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_SIZE_KB,
            ],
        ]);

        $file = $data['file'];
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'message' => 'Tipo de arquivo não permitido.',
                'mime'    => $mime,
            ], 422);
        }

        $me = (int) Auth::id();
        // Path: chat-attachments/{userId}/{yearmonth}/{hash}.ext
        // Espalha entre users e meses pra não encher um único dir.
        $path = $file->store(
            'chat-attachments/' . $me . '/' . now()->format('Ym'),
            'local'
        );

        $attachment = ChatMessageAttachment::create([
            'message_id'       => null, // draft
            'type'             => ChatMessageAttachment::TYPE_UPLOAD,
            'attachable_id'    => null,
            'storage_path'     => $path,
            'original_name'    => $file->getClientOriginalName(),
            'mime_type'        => $mime,
            'size_bytes'       => $file->getSize(),
            'uploader_user_id' => $me,
            'snapshot'         => [
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $mime,
                'size_bytes'    => $file->getSize(),
            ],
        ]);

        return response()->json($attachment->buildPayload(), 201);
    }

    /**
     * Download/preview do arquivo anexado. Valida que o usuário logado é
     * participante da conversa dona da msg.
     */
    public function download(int $attachmentId): StreamedResponse|JsonResponse
    {
        $att = ChatMessageAttachment::with('message.conversation')
            ->find($attachmentId);

        if (!$att || $att->type !== ChatMessageAttachment::TYPE_UPLOAD || !$att->storage_path) {
            abort(404);
        }

        $me = (int) Auth::id();

        // Draft (sem msg) — só o próprio uploader pode acessar.
        if ($att->message_id === null) {
            if ($att->uploader_user_id !== $me) abort(403);
        } else {
            // Anexo "real": precisa participar da conversa.
            $conv = $att->message?->conversation;
            if (!$conv || ($conv->user_a_id !== $me && $conv->user_b_id !== $me)) {
                abort(403);
            }
        }

        if (!Storage::disk('local')->exists($att->storage_path)) {
            abort(404, 'Arquivo não encontrado no storage.');
        }

        return Storage::disk('local')->download(
            $att->storage_path,
            $att->original_name ?? 'arquivo',
            [
                'Content-Type'        => $att->mime_type ?? 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . ($att->original_name ?? 'arquivo') . '"',
            ]
        );
    }

    /**
     * Abre um anexo do tipo `lead_document` pelo ID do attachment (NÃO pelo
     * ID do LeadDocument). Desacoplado do endpoint do CRM a propósito:
     *
     *   - Checa que o user é participante da conversa (mesma regra do upload).
     *   - Consulta o LeadDocument AO VIVO e bloqueia se availability !== available
     *     MESMO PRA ADMIN/GESTOR. Motivo: se a URL do chat foi copiada/salva
     *     antes da exclusão, não deve continuar servindo o arquivo depois.
     *     Admin que precisa auditar um doc em retenção usa o CRM (aba
     *     Documentos do lead), não o link do chat.
     *   - Streama inline com os mesmos headers do preview do CRM.
     *
     * Se o LeadDocument sumiu do banco (purged), o ChatMessageAttachment
     * permanece (histórico) mas esse endpoint devolve 404/403 conforme o caso.
     */
    public function openLeadDocument(Request $request, int $attachmentId): StreamedResponse|JsonResponse
    {
        $att = ChatMessageAttachment::with('message.conversation')
            ->find($attachmentId);

        if (!$att || $att->type !== ChatMessageAttachment::TYPE_LEAD_DOCUMENT) {
            abort(404);
        }

        $me = (int) Auth::id();

        // Participação na conversa. Drafts (message_id null) não acontecem
        // pra lead_document — esse tipo é criado só no store da msg.
        $conv = $att->message?->conversation;
        if (!$conv || ($conv->user_a_id !== $me && $conv->user_b_id !== $me)) {
            abort(403);
        }

        $doc = $att->attachable_id ? LeadDocument::find((int) $att->attachable_id) : null;

        // Availability check vivo — bloqueio global, inclusive admin.
        // Se o doc foi excluído ou tem solicitação em aberto, URL do chat
        // deixa de servir o arquivo pra qualquer um.
        if (!$doc) {
            abort(404, 'Documento excluído permanentemente.');
        }
        if ($doc->deleted_at !== null) {
            abort(403, 'Documento excluído. Acesso indisponível pelo link do chat.');
        }
        if ($doc->isDeletionPending()) {
            abort(403, 'Documento com solicitação de exclusão em aberto. Acesso indisponível pelo link do chat.');
        }

        // Streaming: mesmo padrão do LeadDocumentController@preview, sem a
        // camada de logAccess (anexo do chat já tem rastreio de quem mandou
        // pra quem via ChatMessage). Se virar requisito de LGPD auditar esse
        // canal também, plugamos o LeadDocumentAccess com action='chat_open'.
        $disk = Storage::disk('local');
        $path = $doc->storage_path;
        if (!$disk->exists($path)) {
            // Fallback pro mesmo layout "private/" que o CRM usa.
            if (str_starts_with((string) $path, 'private/')) {
                $alt = substr($path, 8);
                if ($disk->exists($alt)) $path = $alt;
                else abort(404, 'Arquivo não encontrado no storage.');
            } else {
                abort(404, 'Arquivo não encontrado no storage.');
            }
        }

        $mime = $doc->mime_type ?: 'application/octet-stream';
        $size = (int) $doc->size_bytes;
        $safeName = str_replace(['"', "\r", "\n"], ['\\"', '', ''], (string) $doc->original_name);

        $headers = [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . $safeName . '"',
            'Cache-Control'          => 'private, no-store, no-cache, must-revalidate',
            'Pragma'                 => 'no-cache',
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

    /**
     * Cancela um upload draft antes de enviar a msg. O frontend chama isso
     * se o usuário remover o anexo do pending list.
     */
    public function cancelDraft(int $attachmentId): JsonResponse
    {
        $att = ChatMessageAttachment::find($attachmentId);
        if (!$att) return response()->json(['ok' => true]); // idempotente

        // Só cancela se ainda for draft E do próprio usuário.
        if ($att->message_id !== null) {
            return response()->json(['message' => 'Anexo já foi enviado — não pode cancelar.'], 422);
        }
        if ($att->uploader_user_id !== (int) Auth::id()) {
            abort(403);
        }

        // Apaga do storage + row.
        if ($att->storage_path) {
            Storage::disk('local')->delete($att->storage_path);
        }
        $att->delete();

        return response()->json(['ok' => true]);
    }
}
