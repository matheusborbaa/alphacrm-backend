<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Sprint H1.4 — Adiciona `is_terminal` em lead_status.
 *
 * Etapas marcadas como terminais (Vendido, Perdido, Descartado…) NÃO
 * aparecem no desenho do funil da Home, embora continuem existindo no
 * pipeline pra movimentação normal de leads. O número delas continua
 * acessível pelos KPIs do dashboard (Vendas Fechadas, Descartados etc).
 *
 * Default: false (etapa fica no funil). Migration tenta marcar
 * automaticamente as etapas conhecidas pelo nome — admin pode ajustar
 * depois em Configurações → Etapas se quiser.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->boolean('is_terminal')->default(false)->after('color_hex');
        });

        // Marca terminais comuns por padrão. Match case-insensitive +
        // tolerante a variações (Vendido/Perdido/Descartado/Cancelado).
        // Admin pode reverter qualquer um pelo painel se precisar.
        $terminalNames = ['Vendido', 'Perdido', 'Descartado', 'Cancelado'];
        foreach ($terminalNames as $name) {
            DB::table('lead_status')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update(['is_terminal' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('is_terminal');
        });
    }
};
