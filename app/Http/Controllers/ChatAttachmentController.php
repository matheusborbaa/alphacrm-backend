<?php

namespace App\Http\Controllers;

use App\Models\ChatMessageAttachment;
use App\Models\LeadDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    private const MAX_SIZE_KB = 10240;
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

        $path = $file->store(
            'chat-attachments/' . $me . '/' . now()->format('Ym'),
            'local'
        );

        $attachment = ChatMessageAttachment::create([
            'message_id'       => null,
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

    public function download(int $attachmentId): StreamedResponse|JsonResponse
    {
        $att = ChatMessageAttachment::with('message.conversation')
            ->find($attachmentId);

        if (!$att || $att->type !== ChatMessageAttachment::TYPE_UPLOAD || !$att->storage_path) {
            abort(404);
        }

        $me = (int) Auth::id();

        if ($att->message_id === null) {
            if ($att->uploader_user_id !== $me) abort(403);
        } else {

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

    public function openLeadDocument(Request $request, int $attachmentId): StreamedResponse|JsonResponse
    {
        $att = ChatMessageAttachment::with('message.conversation')
            ->find($attachmentId);

        if (!$att || $att->type !== ChatMessageAttachment::TYPE_LEAD_DOCUMENT) {
            abort(404);
        }

        $me = (int) Auth::id();

        $conv = $att->message?->conversation;
        if (!$conv || ($conv->user_a_id !== $me && $conv->user_b_id !== $me)) {
            abort(403);
        }

        $doc = $att->attachable_id ? LeadDocument::find((int) $att->attachable_id) : null;

        if (!$doc) {
            abort(404, 'Documento excluído permanentemente.');
        }
        if ($doc->deleted_at !== null) {
            abort(403, 'Documento excluído. Acesso indisponível pelo link do chat.');
        }
        if ($doc->isDeletionPending()) {
            abort(403, 'Documento com solicitação de exclusão em aberto. Acesso indisponível pelo link do chat.');
        }

        $disk = Storage::disk('local');
        $path = $doc->storage_path;
        if (!$disk->exists($path)) {

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

    public function cancelDraft(int $attachmentId): JsonResponse
    {
        $att = ChatMessageAttachment::find($attachmentId);
        if (!$att) return response()->json(['ok' => true]);

        if ($att->message_id !== null) {
            return response()->json(['message' => 'Anexo já foi enviado — não pode cancelar.'], 422);
        }
        if ($att->uploader_user_id !== (int) Auth::id()) {
            abort(403);
        }

        if ($att->storage_path) {
            Storage::disk('local')->delete($att->storage_path);
        }
        $att->delete();

        return response()->json(['ok' => true]);
    }
}
