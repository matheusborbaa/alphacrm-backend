<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardPeriod
{

    public static function resolve(Request $request, string $default = 'mensal'): array
    {
        $periodo = (string) $request->get('periodo', $default);
        $from    = trim((string) $request->get('from', ''));
        $to      = trim((string) $request->get('to', ''));

        if ($periodo === 'custom' || $from !== '' || $to !== '') {
            return self::customRange($from, $to);
        }

        return match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private static function customRange(string $from, string $to): array
    {
        try {
            $start = $from !== '' ? Carbon::parse($from)->startOfDay() : now()->subDays(30)->startOfDay();
        } catch (\Throwable $e) {
            $start = now()->subDays(30)->startOfDay();
        }

        try {
            $end = $to !== '' ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        } catch (\Throwable $e) {
            $end = now()->endOfDay();
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
