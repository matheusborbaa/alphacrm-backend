<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    private const DISK = 'local';
    private const ROOT = 'media';

    public function contents(Request $request, ?int $folderId = null)
    {
        $folder = $folderId ? MediaFolder::with('parent.parent.parent')->find($folderId) : null;
        if ($folderId && !$folder) {
            return response()->json(['message' => 'Pasta não encontrada.'], 404);
        }

        $user = $request->user();

        if ($folder && !$this->userCanSeeFolder($user, $folder)) {
            return response()->json(['message' => 'Você não tem acesso a esta pasta.'], 403);
        }

        $allowedEmpIds  = $this->allowedEmpreendimentoIds($user);

        $isManager      = $this->isManagerRole($user);
        $allowedLeadIds = $isManager ? null : $this->accessibleLeadIds($user);

        $childFolders = MediaFolder::query()
            ->when($folder, fn ($q) => $q->where('parent_id', $folder->id))
            ->when(!$folder, fn ($q) => $q->whereNull('parent_id'))
            ->when(is_array($allowedEmpIds), function ($q) use ($allowedEmpIds) {

                $q->where(function ($qq) use ($allowedEmpIds) {
                    $qq->whereNull('empreendimento_id')
                       ->orWhereIn('empreendimento_id', $allowedEmpIds);
                });
            })
            ->when(is_array($allowedLeadIds), function ($q) use ($allowedLeadIds) {

                $q->where(function ($qq) use ($allowedLeadIds) {
                    $qq->whereNull('lead_id')
                       ->orWhereIn('lead_id', $allowedLeadIds);
                });
            })
            ->withCount(['children', 'files'])
            ->orderBy('name')
            ->get()
            ->map(fn ($f) => [
                'id'                => $f->id,
                'name'              => $f->name,
                'description'       => $f->description,
                'children_count'    => $f->children_count,
                'files_count'       => $f->files_count,
                'is_system'         => (bool) $f->is_system,
                'empreendimento_id' => $f->empreendimento_id,
                'lead_id'           => $f->lead_id,
                'created_at'        => optional($f->created_at)->toIso8601String(),
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
                'category'      => $f->category,
                'uploader'      => $f->uploader?->name,
                'created_at'    => optional($f->created_at)->toIso8601String(),
                'download_url'  => "/media/files/{$f->id}/download",
                'source'        => 'media',
                'source_label'  => 'Biblioteca',
            ])
            ->all();

        $isLeadRootFolder = $folder && $folder->lead_id;
        if ($isLeadRootFolder) {
            $files = array_merge(
                $files,
                $this->aggregateLeadDocuments($folder->lead_id),
                $this->aggregateCustomFieldFiles($folder->lead_id)
            );

            usort($files, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        }

        $breadcrumb = [];
        $cur = $folder;
        while ($cur) {
            array_unshift($breadcrumb, ['id' => $cur->id, 'name' => $cur->name]);
            $cur = $cur->parent;
        }

        return response()->json([
            'folder'      => $folder ? [
                'id'                => $folder->id,
                'name'              => $folder->name,
                'description'       => $folder->description,
                'is_system'         => (bool) $folder->is_system,
                'empreendimento_id' => $folder->empreendimento_id,
                'lead_id'           => $folder->lead_id,
            ] : null,
            'breadcrumb'  => $breadcrumb,
            'folders'     => $childFolders,
            'files'       => $files,
        ]);
    }

    private function userCanSeeFolder($user, MediaFolder $folder): bool
    {

        $effectiveEmp = $folder->effectiveEmpreendimentoId();
        if ($effectiveEmp) {
            if (!$user) return false;
            if (!$user->canAccessEmpreendimento($effectiveEmp)) return false;
        }

        $effectiveLead = $folder->effectiveLeadId();
        if ($effectiveLead) {
            if (!$user) return false;
            if ($this->isManagerRole($user)) return true;

            $assigned = \App\Models\Lead::whereKey($effectiveLead)
                ->value('assigned_user_id');
            if ((int) $assigned !== (int) $user->id) return false;
        }

        return true;
    }

    private function allowedEmpreendimentoIds($user): ?array
    {
        if (!$user) return [];
        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? '');
        if ($role === 'admin') return null;
        if (($user->empreendimento_access_mode ?? null) === 'all') return null;
        return $user->accessibleEmpreendimentoIds()->all();
    }

    private function accessibleLeadIds($user): array
    {
        if (!$user) return [];
        return \App\Models\Lead::where('assigned_user_id', $user->id)
            ->pluck('id')
            ->all();
    }

    private function isManagerRole($user): bool
    {
        if (!$user) return false;
        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? '');
        return in_array(strtolower((string) $role), ['admin', 'gestor'], true);
    }

    public function storeFolder(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'parent_id'   => 'nullable|integer|exists:media_folders,id',
            'description' => 'nullable|string|max:1000',
        ]);

        $user         = $request->user();
        $parentId     = $data['parent_id'] ?? null;
        $inheritedEmp  = null;
        $inheritedLead = null;

        if ($parentId) {
            $parent = MediaFolder::with('parent.parent.parent')->find($parentId);
            if ($parent) {

                if (!$this->userCanSeeFolder($user, $parent)) {
                    return response()->json(['message' => 'Sem acesso à pasta destino.'], 403);
                }
                $inheritedEmp  = $parent->effectiveEmpreendimentoId();
                $inheritedLead = $parent->effectiveLeadId();
            }
        }

        $folder = MediaFolder::create([
            'name'              => trim($data['name']),
            'parent_id'         => $parentId,
            'empreendimento_id' => $inheritedEmp,
            'lead_id'           => $inheritedLead,
            'description'       => $data['description'] ?? null,
            'created_by'        => auth()->id(),
            'is_system'         => false,
        ]);

        return response()->json([
            'id'                => $folder->id,
            'name'              => $folder->name,
            'parent_id'         => $folder->parent_id,
            'empreendimento_id' => $folder->empreendimento_id,
            'lead_id'           => $folder->lead_id,
            'description'       => $folder->description,
            'is_system'         => false,
            'children_count'    => 0,
            'files_count'       => 0,
        ], 201);
    }

    public function destroyFolder(Request $request, MediaFolder $folder)
    {

        if ($folder->is_system) {
            $msg = 'Esta pasta é gerenciada pelo sistema e não pode ser excluída.';
            if ($folder->empreendimento_id) {
                $msg = 'Esta pasta pertence a um empreendimento e só pode ser removida ao excluir o empreendimento.';
            } elseif ($folder->lead_id) {
                $msg = 'Esta pasta pertence a um lead e só pode ser removida ao excluir o lead.';
            }
            return response()->json(['message' => $msg], 422);
        }

        if ($folder->empreendimento_id) {
            return response()->json([
                'message' => 'Pastas vinculadas a empreendimentos só são removidas ao excluir o empreendimento.',
            ], 422);
        }
        if ($folder->lead_id) {
            return response()->json([
                'message' => 'Pastas vinculadas a leads só são removidas ao excluir o lead.',
            ], 422);
        }

        if (!$this->userCanSeeFolder($request->user(), $folder)) {
            return response()->json(['message' => 'Sem acesso a esta pasta.'], 403);
        }

        $paths = $this->collectFilePathsRecursive($folder);

        $folder->delete();

        Storage::disk(self::DISK)->delete($paths);

        return response()->json(['deleted' => true]);
    }

    private function collectFilePathsRecursive(MediaFolder $folder): array
    {
        $paths = $folder->files()->pluck('storage_path')->all();
        foreach ($folder->children as $child) {
            $paths = array_merge($paths, $this->collectFilePathsRecursive($child));
        }
        return $paths;
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'file'        => 'required|file|max:51200',
            'folder_id'   => 'nullable|integer|exists:media_folders,id',
            'name'        => 'nullable|string|max:200',
            'description' => 'nullable|string|max:1000',

            'category'    => 'nullable|string|max:60',
        ]);

        if ($request->filled('folder_id')) {
            $target = MediaFolder::with('parent.parent.parent')->find($request->input('folder_id'));
            if ($target && !$this->userCanSeeFolder($request->user(), $target)) {
                return response()->json(['message' => 'Sem acesso à pasta destino.'], 403);
            }
        }

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
            'category'         => $request->input('category'),
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

    public function downloadFile(Request $request, MediaFile $file): StreamedResponse
    {

        if ($file->folder_id) {
            $folder = MediaFolder::with('parent.parent.parent')->find($file->folder_id);
            if ($folder && !$this->userCanSeeFolder($request->user(), $folder)) {
                abort(403, 'Sem acesso a este arquivo.');
            }
        }

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

    public function destroyFile(Request $request, MediaFile $file)
    {

        if ($file->folder_id) {
            $folder = MediaFolder::with('parent.parent.parent')->find($file->folder_id);
            if ($folder && !$this->userCanSeeFolder($request->user(), $folder)) {
                return response()->json(['message' => 'Sem acesso a este arquivo.'], 403);
            }
        }

        $path = $file->storage_path;
        $file->delete();
        if ($path) Storage::disk(self::DISK)->delete($path);
        return response()->json(['deleted' => true]);
    }

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

    private function aggregateLeadDocuments(int $leadId): array
    {
        try {
            return \App\Models\LeadDocument::query()
                ->where('lead_id', $leadId)
                ->whereNull('deleted_at')
                ->with('uploader:id,name')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn ($d) => [
                    'id'            => 'doc_' . $d->id,
                    'name'          => $d->original_name ?? ('Documento #' . $d->id),
                    'original_name' => $d->original_name,
                    'mime_type'     => $d->mime_type,
                    'size_bytes'    => (int) ($d->size_bytes ?? 0),
                    'description'   => $d->description,
                    'category'      => $d->category,
                    'uploader'      => $d->uploader?->name,
                    'created_at'    => optional($d->created_at)->toIso8601String(),
                    'download_url'  => "/leads/{$leadId}/documents/{$d->id}/download",
                    'preview_url'   => "/leads/{$leadId}/documents/{$d->id}/preview",
                    'source'        => 'lead_doc',
                    'source_label'  => 'Documento do lead',
                ])
                ->all();
        } catch (\Throwable $e) {
            \Log::warning('Falha ao agregar lead_documents na biblioteca', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
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
                    'id'            => 'cf_' . $v->id,
                    'name'          => $meta['name'] ?? 'Arquivo',
                    'original_name' => $meta['name'] ?? null,
                    'mime_type'     => $meta['mime'] ?? null,
                    'size_bytes'    => (int) ($meta['size'] ?? 0),

                    'description'   => 'Campo customizado: ' . $cfName,
                    'category'      => $cfName,
                    'uploader'      => null,
                    'created_at'    => optional($v->updated_at)->toIso8601String(),
                    'download_url'  => "/leads/{$leadId}/custom-field-files/{$slug}",
                    'source'        => 'custom_field',
                    'source_label'  => 'Campo personalizado',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            \Log::warning('Falha ao agregar custom field files na biblioteca', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }
}
