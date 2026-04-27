<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * E8 — Aplica marca d'água (logo PNG) em imagens do empreendimento.
 *
 * Usa GD nativo do PHP (sem dependência composer extra).
 * Configuração via Settings:
 *   - image_watermark_enabled    (bool)
 *   - image_watermark_logo_path  (string|null) — caminho relativo no disk public
 *   - image_watermark_position   (bottom-right|bottom-left|top-right|top-left|center|tile)
 *   - image_watermark_opacity    (5-100, percentual)
 *   - image_watermark_size_pct   (5-60, percentual da largura da imagem base)
 */
class ImageWatermarkService
{
    public function isEnabled(): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        if (!(bool) Setting::get('image_watermark_enabled', false)) {
            return false;
        }
        $logo = Setting::get('image_watermark_logo_path');
        return !empty($logo) && Storage::disk('public')->exists($logo);
    }

    /**
     * Aplica marca d'água numa imagem (in-place).
     * Aceita path relativo no disk public OU URL "/storage/..." (que normaliza).
     * Retorna true se aplicou; false se não aplicou (com motivo no log).
     */
    public function apply(string $imagePathOrUrl): bool
    {
        if (!$this->isEnabled()) return false;

        $relPath = $this->normalizePath($imagePathOrUrl);
        if (!$relPath) {
            Log::warning('[watermark] Path inválido', ['input' => $imagePathOrUrl]);
            return false;
        }

        if (!Storage::disk('public')->exists($relPath)) {
            Log::warning('[watermark] Imagem não encontrada', ['path' => $relPath]);
            return false;
        }

        $absImage = Storage::disk('public')->path($relPath);
        $absLogo  = Storage::disk('public')->path((string) Setting::get('image_watermark_logo_path'));

        $position  = (string) Setting::get('image_watermark_position', 'bottom-right');
        $opacity   = (int)    Setting::get('image_watermark_opacity', 50);
        $sizePct   = (int)    Setting::get('image_watermark_size_pct', 20);

        try {
            return $this->applyGd($absImage, $absLogo, $position, $opacity, $sizePct);
        } catch (\Throwable $e) {
            Log::error('[watermark] Falha ao aplicar', [
                'path'  => $relPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function applyGd(string $absImage, string $absLogo, string $position, int $opacityPct, int $sizePct): bool
    {

        $info = @getimagesize($absImage);
        if (!$info) return false;
        $mime = $info['mime'] ?? '';

        $base = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absImage),
            'image/png'  => @imagecreatefrompng($absImage),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absImage) : null,
            default      => null,
        };
        if (!$base) {
            Log::warning('[watermark] Formato não suportado pela GD', ['mime' => $mime, 'path' => $absImage]);
            return false;
        }

        $logo = @imagecreatefrompng($absLogo);
        if (!$logo) {
            imagedestroy($base);
            Log::warning('[watermark] Logo PNG inválido', ['logo' => $absLogo]);
            return false;
        }


        imagealphablending($base, true);
        imagesavealpha($base, $mime === 'image/png' || $mime === 'image/webp');
        imagealphablending($logo, true);
        imagesavealpha($logo, true);


        $baseW = imagesx($base);
        $baseH = imagesy($base);
        $logoOrigW = imagesx($logo);
        $logoOrigH = imagesy($logo);


        $targetLogoW = max(20, (int) round($baseW * ($sizePct / 100)));
        $scale       = $targetLogoW / $logoOrigW;
        $targetLogoH = max(10, (int) round($logoOrigH * $scale));


        $logoResized = imagecreatetruecolor($targetLogoW, $targetLogoH);
        imagealphablending($logoResized, false);
        imagesavealpha($logoResized, true);
        $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
        imagefilledrectangle($logoResized, 0, 0, $targetLogoW, $targetLogoH, $transparent);
        imagealphablending($logoResized, true);
        imagecopyresampled(
            $logoResized, $logo,
            0, 0, 0, 0,
            $targetLogoW, $targetLogoH,
            $logoOrigW, $logoOrigH
        );
        imagedestroy($logo);


        $alphaPct = max(5, min(100, $opacityPct));

        if ($position === 'tile') {
            $stepX = $targetLogoW + (int) round($targetLogoW * 0.6);
            $stepY = $targetLogoH + (int) round($targetLogoH * 0.6);
            for ($y = 0; $y < $baseH; $y += $stepY) {
                for ($x = 0; $x < $baseW; $x += $stepX) {
                    $this->copyMergeAlpha($base, $logoResized, $x, $y, $targetLogoW, $targetLogoH, $alphaPct);
                }
            }
        } else {
            $margin = (int) round(min($baseW, $baseH) * 0.025);
            [$x, $y] = $this->resolvePosition($position, $baseW, $baseH, $targetLogoW, $targetLogoH, $margin);
            $this->copyMergeAlpha($base, $logoResized, $x, $y, $targetLogoW, $targetLogoH, $alphaPct);
        }

        imagedestroy($logoResized);


        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($base, $absImage, 92),
            'image/png'  => imagepng($base, $absImage, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($base, $absImage, 90) : false,
            default      => false,
        };
        imagedestroy($base);

        return (bool) $ok;
    }


    private function copyMergeAlpha($dst, $src, int $dstX, int $dstY, int $w, int $h, int $alphaPct): void
    {

        if ($alphaPct >= 99) {
            imagecopy($dst, $src, $dstX, $dstY, 0, 0, $w, $h);
            return;
        }


        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);

        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, $w, $h, $transparent);


        $factor = 1 - ($alphaPct / 100);

        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgba = imagecolorat($src, $x, $y);
                $a    = ($rgba >> 24) & 0x7F;
                if ($a === 127) continue;

                $newA = (int) round($a + (127 - $a) * $factor);
                if ($newA > 127) $newA = 127;
                if ($newA < 0)   $newA = 0;

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $color = imagecolorallocatealpha($tmp, $r, $g, $b, $newA);
                imagesetpixel($tmp, $x, $y, $color);
            }
        }


        imagecopy($dst, $tmp, $dstX, $dstY, 0, 0, $w, $h);
        imagedestroy($tmp);
    }

    private function resolvePosition(string $pos, int $baseW, int $baseH, int $logoW, int $logoH, int $margin): array
    {
        return match ($pos) {
            'bottom-left' => [$margin, $baseH - $logoH - $margin],
            'top-right'   => [$baseW - $logoW - $margin, $margin],
            'top-left'    => [$margin, $margin],
            'center'      => [(int) (($baseW - $logoW) / 2), (int) (($baseH - $logoH) / 2)],
            default       => [$baseW - $logoW - $margin, $baseH - $logoH - $margin],
        };
    }

    private function normalizePath(string $input): ?string
    {
        $s = trim($input);
        if ($s === '') return null;

        $s = preg_replace('#^/?storage/#', '', $s);
        $s = ltrim((string) $s, '/');

        return $s ?: null;
    }
}
