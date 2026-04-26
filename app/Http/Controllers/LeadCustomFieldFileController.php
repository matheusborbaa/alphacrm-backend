<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadHistory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadCustomFieldFileController extends Controller
{
    use AuthorizesRequests;

    private const DISK = 'local';

    private const ROOT = 'lead_custom_files';

    public function store(Request $request, Lead $lead, string $slug)
    {
        $this->authorize('update', $lead);

        $field = $this->resolveFileField($slug);
        $config = $this->fileConfig($field);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . ($config['max_mb'] * 1024),
            ],
        ]);

        $uploaded = $request->file('file');

        if (!empty($config['accept'])) {
            $allowed = $this->parseAccept($config['accept']);
            $ext     = strtolower($uploaded->getClientOriginalExtension());
            if (!empty($allowed) && !in_array('.' . $ext, $allowed, true)) {
                return response()->json([
                    'message' => 'Tipo de arquivo não permitido. Aceita: ' . implode(', ', $allowed),
                ], 422);
            }
        }

        $existing = LeadCustomFieldValue::where('lead_id', $lead->id)
            ->where('custom_field_id', $field->id)
            ->first();

        $oldMeta = $existing?->value ? json_decode($existing->value, true) : null;
        if (is_array($oldMeta) && !empty($oldMeta['path'])) {
            Storage::disk(self::DISK)->delete($oldMeta['path']);
        }

        $originalName = $uploaded->getClientOriginalName();
        $safeName     = $this->sanitizeFilename($originalName);
        $storedName   = time() . '_' . Str::random(6) . '_' . $safeName;

        $relPath = sprintf('%s/%d/%d/%s', self::ROOT, $lead->id, $field->id, $storedName);
        Storage::disk(self::DISK)->putFileAs(
            dirname($relPath),
            $uploaded,
            basename($relPath)
        );

        $meta = [
            'path' => $relPath,
            'name' => $originalName,
            'size' => $uploaded->getSize(),
            'mime' => $uploaded->getMimeType(),
        ];
        $newValue = json_encode($meta, JSON_UNESCAPED_UNICODE);

        LeadCustomFieldValue::updateOrCreate(
            ['lead_id' => $lead->id, 'custom_field_id' => $field->id],
            ['value'   => $newValue]
        );

        $oldName = is_array($oldMeta) ? ($oldMeta['name'] ?? null) : null;
        LeadHistory::logFieldChangeDiffs($lead, [[
            'label' => $field->name ?: $field->slug,
            'from'  => $oldName ? "Arquivo: {$oldName}" : null,
            'to'    => "Arquivo: {$originalName}",
        ]]);

        return response()->json([
            'name' => $originalName,
            'size' => $meta['size'],
            'mime' => $meta['mime'],
        ], 201);
    }

    public function download(Request $request, Lead $lead, string $slug): StreamedResponse
    {
        $this->authorize('update', $lead);

        $field = $this->resolveFileField($slug);
        $val   = LeadCustomFieldValue::where('lead_id', $lead->id)
            ->where('custom_field_id', $field->id)
            ->first();

        $meta = $val?->value ? json_decode($val->value, true) : null;
        if (!is_array($meta) || empty($meta['path'])) {
            abort(404, 'Arquivo não encontrado.');
        }

        $disk = Storage::disk(self::DISK);
        if (!$disk->exists($meta['path'])) {
            abort(404, 'Arquivo não está mais disponível no servidor.');
        }

        $inline       = $request->boolean('inline');
        $disposition  = $inline ? 'inline' : 'attachment';
        $originalName = $meta['name'] ?? basename($meta['path']);
        $mime         = $meta['mime'] ?? 'application/octet-stream';

        return response()->streamDownload(function () use ($disk, $meta) {
            $stream = $disk->readStream($meta['path']);
            if ($stream === false) {
                abort(500, 'Falha ao ler arquivo.');
            }
            fpassthru($stream);
            fclose($stream);
        }, $originalName, [
            'Content-Type'        => $mime,
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($originalName)),
        ]);
    }

    public function destroy(Request $request, Lead $lead, string $slug)
    {
        $this->authorize('update', $lead);

        $field = $this->resolveFileField($slug);
        $val   = LeadCustomFieldValue::where('lead_id', $lead->id)
            ->where('custom_field_id', $field->id)
            ->first();

        if (!$val) {
            return response()->json(['removed' => false]);
        }

        $meta = $val->value ? json_decode($val->value, true) : null;
        $oldName = is_array($meta) ? ($meta['name'] ?? null) : null;

        if (is_array($meta) && !empty($meta['path'])) {
            Storage::disk(self::DISK)->delete($meta['path']);
        }

        $val->value = null;
        $val->save();

        if ($oldName) {
            LeadHistory::logFieldChangeDiffs($lead, [[
                'label' => $field->name ?: $field->slug,
                'from'  => "Arquivo: {$oldName}",
                'to'    => null,
            ]]);
        }

        return response()->json(['removed' => true]);
    }

    private function resolveFileField(string $slug): CustomField
    {
        $field = CustomField::where('slug', $slug)->first();
        if (!$field) {
            abort(404, "Custom field '{$slug}' não encontrado.");
        }
        if ($field->type !== 'file') {
            abort(422, "Custom field '{$slug}' não é do tipo arquivo.");
        }
        if (!$field->active) {
            abort(422, "Custom field '{$slug}' está inativo.");
        }
        return $field;
    }

    private function fileConfig(CustomField $field): array
    {
        $opts = is_array($field->options) ? $field->options : [];
        return [
            'max_mb' => isset($opts['max_mb']) && (int) $opts['max_mb'] > 0
                ? (int) $opts['max_mb']
                : CustomField::FILE_DEFAULT_MAX_MB,
            'accept' => $opts['accept'] ?? null,
        ];
    }

    private function parseAccept(string $accept): array
    {
        $parts = preg_split('/[,;\s]+/', strtolower(trim($accept)));
        $clean = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if ($p[0] !== '.') $p = '.' . $p;
            $clean[] = $p;
        }
        return array_values(array_unique($clean));
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
}
