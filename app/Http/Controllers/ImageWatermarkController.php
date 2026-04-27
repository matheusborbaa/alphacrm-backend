<?php

namespace App\Http\Controllers;

use App\Models\EmpreendimentoImage;
use App\Models\Setting;
use App\Services\ImageWatermarkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * E8 — Endpoints de gestão da marca d'água.
 *   POST   /admin/settings/image-watermark/logo     — upload do PNG
 *   DELETE /admin/settings/image-watermark/logo     — remove
 *   POST   /admin/empreendimento-images/apply-watermark — aplica nas existentes
 *   GET    /admin/empreendimento-images/watermark-stats — quantas têm/não têm
 */
class ImageWatermarkController extends Controller
{
    public function uploadLogo(Request $request)
    {
        $this->ensureAdmin($request);

        $request->validate([
            'logo' => 'required|file|image|mimes:png|max:2048',
        ]);

        $file = $request->file('logo');


        $oldPath = Setting::get('image_watermark_logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            try { Storage::disk('public')->delete($oldPath); } catch (\Throwable $e) {}
        }

        $stored = $file->storeAs(
            'system',
            'watermark-logo-' . time() . '.png',
            'public'
        );

        Setting::set('image_watermark_logo_path', $stored);


        if (!Setting::get('image_watermark_enabled', false)) {
            Setting::set('image_watermark_enabled', true);
        }

        return response()->json([
            'logo_path' => $stored,
            'logo_url'  => Storage::url($stored),
        ]);
    }

    public function deleteLogo(Request $request)
    {
        $this->ensureAdmin($request);

        $oldPath = Setting::get('image_watermark_logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            try { Storage::disk('public')->delete($oldPath); } catch (\Throwable $e) {}
        }

        Setting::set('image_watermark_logo_path', null);
        Setting::set('image_watermark_enabled', false);

        return response()->json(['success' => true]);
    }

    public function stats(Request $request)
    {
        $this->ensureAdmin($request);

        $total       = EmpreendimentoImage::count();
        $watermarked = EmpreendimentoImage::whereNotNull('watermark_applied_at')->count();

        return response()->json([
            'total'       => $total,
            'watermarked' => $watermarked,
            'pending'     => max(0, $total - $watermarked),
        ]);
    }

    public function applyToExisting(Request $request, ImageWatermarkService $service)
    {
        $this->ensureAdmin($request);

        if (!$service->isEnabled()) {
            return response()->json([
                'message' => 'Marca d\'água não está habilitada ou logo não foi enviado.',
            ], 422);
        }

        $force = (bool) $request->boolean('force');
        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(200, $limit));

        $query = EmpreendimentoImage::query();
        if (!$force) {
            $query->whereNull('watermark_applied_at');
        }

        $batch = $query->orderBy('id')->limit($limit)->get();

        $applied = 0;
        $skipped = 0;
        foreach ($batch as $img) {
            $ok = $service->apply($img->image_path);
            if ($ok) {
                $img->update(['watermark_applied_at' => now()]);
                $applied++;
            } else {
                $skipped++;
            }
        }


        $remaining = $force
            ? max(0, EmpreendimentoImage::count() - ($applied))
            : EmpreendimentoImage::whereNull('watermark_applied_at')->count();

        return response()->json([
            'applied'   => $applied,
            'skipped'   => $skipped,
            'remaining' => $remaining,
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        $u = $request->user();
        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }
}
