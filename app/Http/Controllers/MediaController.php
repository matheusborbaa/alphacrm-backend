<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD da biblioteca de mídia (Área do Corretor → Biblioteca).
 *
 * Permissions usadas (rotas em routes/api.php):
 *   media.view          → listar pastas/arquivos + download
 *   media.upload        → subir arquivo
 *   media.create_folder → criar pasta
 *   media.delete        → apagar pasta/arquivo (CASCADE: pasta apaga
 *                         subpastas e arquivos junto)
 *
 * Storage: disco 'local' (storage/app/media/...). Não acessível via URL
 * pública — tudo vai pelo endpoint download() que valida permission.
 */
class MediaController extends Controller
{
    private const DISK = 'local';
    private const ROOT = 'media';

    /* ====================================================================
     * PASTAS
     * ==================================================================== */

    /**
     * GET /media/folders/{folder?}/contents
     *
     * Devolve filhos diretos (pastas + arquivos) da pasta indicada.
     * `folder=null` ou ausente = raiz da biblioteca.
     *
     * Inclui breadcrumb (ancestrais até a raiz) pra UI montar nav.
     */
    public function contents(Request $request, ?int $folderId = null)
    {
        $folder = $folderId ? MediaFolder::with('parent.parent.parent')->find($folderId) : null;
        if ($folderId && !$folder) {
            return response()->json(['message' => 'Pasta não encontrada.'], 404);
        }

        $childFolders = MediaFolder::query()
            ->when($folder, fn ($q) => $q->where('parent_id', $folder->id))
            ->when(!$folder, fn ($q) => $q->whereNull('parent_id'))
            ->withCount(['children', 'files'])
            ->orderBy('name')
            ->get()
            ->map(fn ($f) => [
                'id'            => $f->id,
                'name'          => $f->name,
                'description'   => $f->description,
                'children_count'=> $f->children_count,
                'files_count'   => $f->files_count,
                'created_at'    => optional($f->created_at)->toIso8601String(),
            ]);

        $files = MediaFile::query()
            ->when($folder, fn ($q) => $q->where('folder_id', $folder->id))
            ->when(!$folder, fn ($q) => $q->whereNull('folder_id'))
            ->with('uploader:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn ($f) => [
                'id'            => $f->id,
                'name'          => $f->name,
                'original_name' => $f->original_name,
                'mime_type'     => $f->mime_type,
                'size_bytes'    => $f->size_bytes,
                'description'   => $f->description,
                'uploader'      => $f->uploader?->name,
                'created_at'    => optional($f->created_at)->toIso8601String(),
                'download_url'  => "/media/files/{$f->id}/download",
            ]);

        // Breadcrumb (raiz → pasta atual)
        $breadcrumb = [];
        $cur = $folder;
        while ($cur) {
            array_unshift($breadcrumb, ['id' => $cur->id, 'name' => $cur->name]);
            $cur = $cur->parent;
        }

        return response()->json([
            'folder'      => $folder ? ['id' => $folder->id, 'name' => $folder->name, 'description' => $folder->description] : null,
            'breadcrumb'  => $breadcrumb,
            'folders'     => $childFolders,
            'files'       => $files,
        ]);
    }

    /**
     * POST /media/folders
     * Body: { name, parent_id? }
     */
    public function storeFolder(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'parent_id'   => 'nullable|integer|exists:media_folders,id',
            'description' => 'nullable|string|max:1000',
        ]);

        $folder = MediaFolder::create([
            'name'        => trim($data['name']),
            'parent_id'   => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by'  => auth()->id(),
        ]);

        return response()->json([
            'id'            => $folder->id,
            'name'          => $folder->name,
            'parent_id'     => $folder->parent_id,
            'description'   => $folder->description,
            'children_count'=> 0,
            'files_count'   => 0,
        ], 201);
    }

    /**
     * DELETE /media/folders/{folder}
     * Cascade: apaga subpastas e arquivos juntos. Tenta apagar arquivos
     * físicos do disco também.
     */
    public function destroyFolder(MediaFolder $folder)
    {
        // Antes de deletar do banco, junta paths físicos pra apagar do disco
        $paths = $this->collectFilePathsRecursive($folder);

        $folder->delete();

        Storage::disk(self::DISK)->delete($paths);

        return response()->json(['deleted' => true]);
    }

    /**
     * Recursivamente coleta storage_paths de TODOS os arquivos
     * descendentes da pasta (filhos diretos + subpastas).
     */
    private function collectFilePathsRecursive(MediaFolder $folder): array
    {
        $paths = $folder->files()->pluck('storage_path')->all();
        foreach ($folder->children as $child) {
            $paths = array_merge($paths, $this->collectFilePathsRecursive($child));
        }
        return $paths;
    }

    /* ====================================================================
     * ARQUIVOS
     * ==================================================================== */

    /**
     * POST /media/files (multipart)
     * Body: file, folder_id?, name?, description?
     */
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file'        => 'required|file|max:51200',  // 50MB
            'folder_id'   => 'nullable|integer|exists:media_folders,id',
            'name'        => 'nullable|string|max:200',
            'description' => 'nullable|string|max:1000',
        ]);

        $upload  = $request->file('file');
        $orig    = $upload->getClientOriginalName();
        $safe    = $this->sanitizeFilename($orig);
        $stored  = time() . '_' . Str::random(6) . '_' . $safe;
        $relPath = sprintf('%s/%s', self::ROOT, $stored);

        Storage::disk(self::DISK)->putFileAs(self::ROOT, $upload, $stored);

        $file = MediaFile::create([
            'folder_id'        => $request->input('folder_id'),
            'name'             => trim($request->input('name', '')) ?: $orig,
            'original_name'    => $orig,
            'storage_path'     => $relPath,
            'mime_type'        => $upload->getMimeType(),
            'size_bytes'       => $upload->getSize(),
            'uploader_user_id' => auth()->id(),
            'description'      => $request->input('description'),
        ]);

        return response()->json([
            'id'            => $file->id,
            'name'          => $file->name,
            'original_name' => $file->original_name,
            'mime_type'     => $file->mime_type,
            'size_bytes'    => $file->size_bytes,
            'download_url'  => "/media/files/{$file->id}/download",
        ], 201);
    }

    /**
     * GET /media/files/{file}/download?inline=1?
     * Streamed download. ?inline=1 → Content-Disposition: inline (preview).
     */
    public function downloadFile(Request $request, MediaFile $file): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        if (!$disk->exists($file->storage_path)) {
            abort(404, 'Arquivo não encontrado no servidor.');
        }

        $inline      = $request->boolean('inline');
        $disposition = $inline ? 'inline' : 'attachment';
        $name        = $file->original_name ?: $file->name;
        $mime        = $file->mime_type ?: 'application/octet-stream';

        return response()->streamDownload(function () use ($disk, $file) {
            $stream = $disk->readStream($file->storage_path);
            if ($stream === false) abort(500, 'Falha ao ler arquivo.');
            fpassthru($stream);
            fclose($stream);
        }, $name, [
            'Content-Type'        => $mime,
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($name)),
        ]);
    }

    /**
     * DELETE /media/files/{file}
     */
    public function destroyFile(MediaFile $file)
    {
        $path = $file->storage_path;
        $file->delete();
        if ($path) Storage::disk(self::DISK)->delete($path);
        return response()->json(['deleted' => true]);
    }

    /* ====================================================================
     * HELPERS
     * ==================================================================== */

    private function sanitizeFilename(string $name): string
    {
        $info = pathinfo($name);
        $base = $info['filename'] ?? 'arquivo';
        $ext  = isset($info['extension']) ? '.' . strtolower($info['extension']) : '';

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim($base, '_');
        if ($base === '') $base = 'arquivo';

        return $base . $ext;
    }
}
