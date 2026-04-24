<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use App\Models\EmpreendimentoImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmpreendimentoImageController extends Controller
{
    /**
     * Upload de imagem para a galeria de um empreendimento.
     *
     * Armazena em storage público (disk 'public') e devolve o registro
     * já com `image_path` apontando pra URL servível (/storage/...). O
     * frontend só precisa prefixar com o host.
     *
     * Guarda pré-validação pra post_max_size/upload_max_filesize exceeded
     * — nesses casos o PHP zera $_FILES silenciosamente e a validação
     * padrão do Laravel só diz "o arquivo é obrigatório", mascarando o
     * motivo real (limite de upload estourado).
     */
    public function store(Request $request, Empreendimento $empreendimento)
    {
        // Detecta post_max_size estourado: o PHP zera $_POST/$_FILES mas o
        // CONTENT_LENGTH vem populado. Sem essa guarda cai na validação
        // genérica "required" e o usuário não entende o que aconteceu.
        $contentLength = (int) $request->server('CONTENT_LENGTH');
        $postMax       = $this->iniBytes(ini_get('post_max_size'));

        if ($postMax > 0 && $contentLength > 0 && $contentLength > $postMax) {
            return response()->json([
                'message' => 'Imagem maior que o limite do servidor ('
                    . $this->formatBytes($postMax) . '). Tente uma foto menor.',
            ], 413);
        }

        try {
            $request->validate([
                'image'    => 'required|file|image|mimes:jpg,jpeg,png,webp,heic|max:20480',
                'order'    => 'nullable|integer',
                'category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(EmpreendimentoImage::CATEGORIES)],
                'is_cover' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            // Se o único erro é "image required" e tinha payload, provavelmente
            // upload_max_filesize foi o causa.
            $errors = $e->errors();
            $onlyImageRequired = isset($errors['image'])
                && count($errors) === 1
                && count($errors['image']) === 1;

            if ($onlyImageRequired && $contentLength > 0) {
                $uploadMax = $this->iniBytes(ini_get('upload_max_filesize'));
                return response()->json([
                    'message' => 'Imagem maior que o limite do servidor ('
                        . $this->formatBytes($uploadMax) . '). Tente uma foto menor.',
                ], 413);
            }

            throw $e;
        }

        $file = $request->file('image');

        // Safety-net pra UPLOAD_ERR_*; o validador já trata mime/size mas
        // erros tipo NO_TMP_DIR / CANT_WRITE passariam batido.
        if (!$file->isValid()) {
            $errorMap = [
                UPLOAD_ERR_INI_SIZE   => 'Imagem maior que o limite do servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'Imagem maior que o limite do formulário.',
                UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Servidor sem diretório temporário. Avise o admin.',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo no servidor. Avise o admin.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP. Avise o admin.',
            ];
            $code = $file->getError();
            $msg  = $errorMap[$code] ?? "Falha no upload (código {$code}).";
            return response()->json(['message' => $msg], 400);
        }

        try {
            // `store` na disk 'public' devolve path cru tipo
            // "empreendimentos/abc/xyz.jpg". Guardamos com Storage::url pra
            // o frontend só precisar prefixar o host.
            $slug = $empreendimento->code
                ? Str::slug($empreendimento->code)
                : $empreendimento->id;

            $path = $file->store("empreendimentos/{$slug}", 'public');

            $category = $request->input('category', EmpreendimentoImage::CATEGORY_IMAGENS);
            $wantCover = (bool) $request->boolean('is_cover');

            // Se for marcada como capa já na criação, limpa a flag das outras.
            if ($wantCover) {
                EmpreendimentoImage::where('empreendimento_id', $empreendimento->id)
                    ->update(['is_cover' => false]);
            }

            $img = EmpreendimentoImage::create([
                'empreendimento_id' => $empreendimento->id,
                'image_path'        => Storage::url($path),
                'order'             => (int) ($request->input('order') ?? 0),
                'category'          => $category,
                'is_cover'          => $wantCover,
            ]);

            // Espelha a capa na coluna cover_image do empreendimento pra manter
            // compat com listagens antigas que leem direto. Se o empreendimento
            // ainda não tinha cover_image, auto-ativa (regra: sem capa = inativo).
            if ($wantCover) {
                $empreendimento->update([
                    'cover_image' => $img->image_path,
                    'active'      => true,
                ]);
            }

            return $img;
        } catch (\Throwable $e) {
            Log::error('Falha ao salvar imagem de empreendimento', [
                'empreendimento_id' => $empreendimento->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Erro interno ao salvar a imagem. Avise o administrador.',
            ], 500);
        }
    }

    /**
     * Marca uma imagem como capa do empreendimento. Limpa a flag das outras
     * imagens do mesmo empreendimento e atualiza empreendimentos.cover_image
     * (mantendo compat com listagens/cards antigos).
     */
    public function setCover(Request $request, EmpreendimentoImage $image)
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        EmpreendimentoImage::where('empreendimento_id', $image->empreendimento_id)
            ->update(['is_cover' => false]);

        $image->update(['is_cover' => true]);

        // Atualiza a capa e — se era a primeira capa — auto-ativa o empreendimento.
        $emp = Empreendimento::find($image->empreendimento_id);
        $wasInactive = !$emp->active;

        $emp->update([
            'cover_image' => $image->image_path,
            'active'      => true,
        ]);

        return response()->json([
            'success'         => true,
            'image'           => $image->fresh(),
            'just_activated'  => $wasInactive, // frontend usa pra exibir toast "ativado!"
            'empreendimento_active' => true,
        ]);
    }

    /**
     * Remove imagem da galeria. Só admin/gestor chegam aqui (rota protegida).
     * Apaga o arquivo físico (best-effort) e depois a row.
     */
    public function destroy(Request $request, EmpreendimentoImage $image)
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        // image_path é salvo como "/storage/empreendimentos/.../x.jpg" —
        // pra acessar via disk('public') precisamos tirar o prefixo.
        $relative = ltrim(preg_replace('#^/?storage/#', '', (string) $image->image_path), '/');

        if ($relative && Storage::disk('public')->exists($relative)) {
            try {
                Storage::disk('public')->delete($relative);
            } catch (\Throwable $e) {
                Log::warning('Falha ao deletar arquivo da galeria', [
                    'image_id' => $image->id,
                    'path'     => $relative,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $wasCover         = (bool) $image->is_cover;
        $empreendimentoId = $image->empreendimento_id;

        $image->delete();

        // Se removeu a capa: desativa o empreendimento e tenta eleger
        // outra imagem da mesma categoria ('imagens' de preferência)
        // como nova capa. Se não há mais imagens, fica sem capa e inativo.
        $justDeactivated = false;
        if ($wasCover) {
            $next = EmpreendimentoImage::where('empreendimento_id', $empreendimentoId)
                ->orderByRaw("CASE WHEN category = 'imagens' THEN 0 ELSE 1 END")
                ->orderBy('order')
                ->orderBy('id')
                ->first();

            if ($next) {
                $next->update(['is_cover' => true]);
                Empreendimento::where('id', $empreendimentoId)
                    ->update(['cover_image' => $next->image_path]);
            } else {
                Empreendimento::where('id', $empreendimentoId)->update([
                    'cover_image' => null,
                    'active'      => false,
                ]);
                $justDeactivated = true;
            }
        }

        return response()->json([
            'deleted'           => true,
            'just_deactivated'  => $justDeactivated,
        ]);
    }

    /* =========================================================================
     * HELPERS
     * =======================================================================*/

    /**
     * Converte valor de ini ("20M", "2G", "8388608") em bytes.
     */
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

    /**
     * Formata bytes em "15 MB" / "512 KB".
     */
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
