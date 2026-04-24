<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Sprint 3.5a — helper pra resolver o período dos endpoints do dashboard.
 *
 * Antes cada endpoint tinha um switch inline só entendendo:
 *   'diario' | 'semanal' | 'mensal' (default).
 *
 * Agora o frontend também pode passar:
 *   ?periodo=custom&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * quando o usuário usa o seletor "De / Até / Aplicar filtro" do bloco
 * "Resumo de Produtividade". Esse helper cobre os 4 casos e devolve
 * [Carbon $start, Carbon $end] consistente.
 *
 * Se `from` vier sem `to`, $end = hoje. Se só `to`, $start = 30 dias antes.
 * Se o range for inválido (from > to), faz swap.
 */
class DashboardPeriod
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
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

        // Swap defensivo: se o front mandou invertido, corrige em vez de devolver vazio.
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
