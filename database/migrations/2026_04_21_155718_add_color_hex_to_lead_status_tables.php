<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona `color_hex` nas tabelas lead_status e lead_substatus.
 *
 * Justificativa: antes as cores eram hardcoded no frontend (kanban.js, lead.js).
 * Agora cada etapa/subetapa tem sua cor configurável, que é propagada pro
 * kanban, lista de leads, timeline e gráficos do dashboard.
 *
 * Formato: string de 7 caracteres no padrão hex (#RRGGBB). NULL significa
 * "usa a cor padrão do status pai" (pro substatus) ou um cinza neutro (pro
 * status raiz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('order');
        });

        Schema::table('lead_substatus', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('order');
        });

        // Seeds iniciais — paleta padrão pras etapas existentes.
        // Usa uma rotação de cores curadas pra dar variação visual
        // sem quebrar quem já tinha o pipeline populado.
        $palette = [
            '#3B82F6', // azul
            '#10B981', // verde
            '#F59E0B', // laranja
            '#EF4444', // vermelho
            '#8B5CF6', // roxo
            '#EC4899', // rosa
            '#06B6D4', // ciano
            '#84CC16', // lima
            '#F97316', // âmbar
            '#6366F1', // índigo
            '#14B8A6', // teal
            '#A855F7', // violeta
        ];

        $statuses = DB::table('lead_status')->orderBy('order')->get(['id']);
        foreach ($statuses as $i => $status) {
            DB::table('lead_status')
                ->where('id', $status->id)
                ->update(['color_hex' => $palette[$i % count($palette)]]);
        }
        // Substatus ficam com color_hex NULL de início — herda do status pai.
    }

    public function down(): void
    {
        Schema::table('lead_status', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });

        Schema::table('lead_substatus', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });
    }
};
