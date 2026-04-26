<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona `is_discard` em lead_status.
 *
 * Diferente de `is_terminal` (que cobre TODA etapa final — Vendido,
 * Perdido, Descartado, Cancelado), `is_discard` marca somente as etapas
 * de ABANDONO do lead — onde o corretor desistiu por algum motivo
 * (sem interesse, sem perfil, telefone errado, lead frio etc).
 *
 * Por que precisamos da distinção?
 *
 *   O fluxo padrão de "campos obrigatórios da etapa" cobra TODAS as
 *   regras das etapas anteriores quando o lead avança ("desde o início").
 *   Faz sentido pra Vendido (queremos histórico comercial completo).
 *   NÃO faz sentido pra Descartado/Perdido — o lead foi abandonado
 *   justamente porque o corretor NÃO conseguiu coletar essas infos.
 *   Cobrar tudo de novo bloqueia o descarte e gera fricção sem valor.
 *
 * Com is_discard=true, o LeadStatusRequirementValidator pula a cascata
 * e cobra apenas as regras configuradas para a própria etapa de
 * descarte (típico: "motivo do descarte" custom field).
 *
 * Default: false. Migration auto-marca Perdido/Descartado/Cancelado
 * (NÃO Vendido — esse é venda, fluxo diferente). Admin pode ajustar
 * depois em Configurações → Etapas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->boolean('is_discard')->default(false)->after('is_terminal');
        });

        // Auto-marca etapas de abandono comuns. Match case-insensitive.
        // Vendido NÃO entra — é terminal mas é fechamento positivo.
        $discardNames = ['Perdido', 'Descartado', 'Cancelado'];
        foreach ($discardNames as $name) {
            DB::table('lead_status')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->update(['is_discard' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('is_discard');
        });
    }
};
