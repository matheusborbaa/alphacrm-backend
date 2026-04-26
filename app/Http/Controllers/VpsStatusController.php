<?php

namespace App\Http\Controllers;

use App\Services\HostingerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpsStatusController extends Controller
{

    private const DISK_THRESHOLD_PERCENT = 75.0;
    private const RAM_THRESHOLD_PERCENT  = 90.0;

    public function __construct(private HostingerService $hostinger) {}

    public function show(Request $request): JsonResponse
    {
        $refresh = $request->boolean('refresh');

        $payload = $refresh
            ? $this->hostinger->refreshStatus()
            : $this->hostinger->getStatus();

        return response()->json($payload);
    }

    public function capacityAlerts(): JsonResponse
    {
        if (!$this->hostinger->isConfigured()) {
            return response()->json(['ok' => true, 'alerts' => []]);
        }

        $status = $this->hostinger->getStatus();
        if (!($status['ok'] ?? false)) {

            return response()->json(['ok' => true, 'alerts' => []]);
        }

        $alerts = [];

        $diskPct = (float) ($status['disk_percent'] ?? 0);
        if ($diskPct >= self::DISK_THRESHOLD_PERCENT) {
            $alerts[] = [
                'metric'      => 'disk',
                'percent'     => round($diskPct, 1),
                'threshold'   => (int) self::DISK_THRESHOLD_PERCENT,
                'used_bytes'  => (int) ($status['disk_used_bytes']  ?? 0),
                'total_bytes' => (int) ($status['disk_total_bytes'] ?? 0),
            ];
        }

        $ramPct = (float) ($status['ram_percent'] ?? 0);
        if ($ramPct >= self::RAM_THRESHOLD_PERCENT) {
            $alerts[] = [
                'metric'      => 'ram',
                'percent'     => round($ramPct, 1),
                'threshold'   => (int) self::RAM_THRESHOLD_PERCENT,
                'used_bytes'  => (int) ($status['ram_used_bytes']  ?? 0),
                'total_bytes' => (int) ($status['ram_total_bytes'] ?? 0),
            ];
        }

        return response()->json([
            'ok'     => true,
            'alerts' => $alerts,
        ]);
    }
}
