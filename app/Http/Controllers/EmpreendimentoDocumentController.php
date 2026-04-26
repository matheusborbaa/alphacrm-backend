<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmpreendimentoDocumentController extends Controller
{
    private const ALLOWED_SLOTS = ['book', 'price_table'];

    public function upload(Request $request, Empreendimento $empreendimento, string $slot)
    {
        abort_unless(in_array($slot, self::ALLOWED_SLOTS, true), 404);
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        $contentLength = (int) $request->server('CONTENT_LENGTH');
        $postMax       = $this->iniBytes(ini_get('post_max_size'));

        if ($postMax > 0 && $contentLength > 0 && $contentLength > $postMax) {
            return response()->json([
                'message' => 'Arquivo maior que o limite do servidor ('
                    . $this->formatBytes($postMax) . ').',
            ], 413);
        }

        try {
            $request->validate([
                'file' => 'required|file|mimes:pdf|max:20480',
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $onlyRequired = isset($errors['file']) && count($errors) === 1;

            if ($onlyRequired && $contentLength > 0) {
                $uploadMax = $this->iniBytes(ini_get('upload_max_filesize'));
                return response()->json([
                    'message' => 'Arquivo maior que o limite do servidor ('
                        . $this->formatBytes($uploadMax) . ').',
                ], 413);
            }
            throw $e;
        }

        $file = $request->file('file');

        if (!$file->isValid()) {
            return response()->json(['message' => 'Upload inválido (código ' . $file->getError() . ').'], 400);
        }

        try {
            $slug = $empreendimento->code
                ? Str::slug($empreendimento->code)
                : $empreendimento->id;

            $path = $file->store("empreendimentos/{$slug}/docs", 'public');

            $pathCol = $slot . '_path';
            $timeCol = $slot . '_uploaded_at';

            if ($empreendimento->{$pathCol}) {
                $oldRel = ltrim(preg_replace('#^/?storage/#', '', (string) $empreendimento->{$pathCol}), '/');
                if ($oldRel && Storage::disk('public')->exists($oldRel)) {
                    Storage::disk('public')->delete($oldRel);
                }
            }

            $empreendimento->update([
                $pathCol => Storage::url($path),
                $timeCol => Carbon::now(),
            ]);

            return response()->json([
                'success'  => true,
                'slot'     => $slot,
                'path'     => $empreendimento->{$pathCol},
                'uploaded_at' => $empreendimento->{$timeCol},
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao salvar documento do empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'slot'              => $slot,
                'error'             => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Erro interno ao salvar o documento.'], 500);
        }
    }

    public function destroy(Request $request, Empreendimento $empreendimento, string $slot)
    {
        abort_unless(in_array($slot, self::ALLOWED_SLOTS, true), 404);
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        $pathCol = $slot . '_path';
        $timeCol = $slot . '_uploaded_at';

        $current = $empreendimento->{$pathCol};
        if ($current) {
            $rel = ltrim(preg_replace('#^/?storage/#', '', (string) $current), '/');
            if ($rel && Storage::disk('public')->exists($rel)) {
                try { Storage::disk('public')->delete($rel); } catch (\Throwable $e) {}
            }
        }

        $empreendimento->update([
            $pathCol => null,
            $timeCol => null,
        ]);

        return response()->json(['deleted' => true]);
    }

    private function iniBytes(?string $val): int
    {
        if ($val === null || $val === '') return 0;
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $num = (int) $val;
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
        $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $val = $bytes / pow(1024, $pow);
        return (fmod($val, 1) === 0.0 ? number_format($val, 0) : number_format($val, 1))
            . ' ' . $units[$pow];
    }
}
