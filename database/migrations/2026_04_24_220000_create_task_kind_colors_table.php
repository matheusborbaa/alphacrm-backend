<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 3.6a — Cores configuráveis por tipo de tarefa.
 *
 * Tabela pequena (1 linha por kind) que o admin pode editar via
 * Configurações → Geral. O frontend carrega uma vez no boot e aplica
 * nos badges que aparecem no card de tarefa (agenda, home, lead).
 *
 * Defaults alinhados com o mapa hard-coded antigo (TYPE_META em agenda.js)
 * pra não mudar visual pra quem não tocar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_kind_colors', function (Blueprint $table) {
            // O 'kind' é a chave natural (bate com Appointment::KINDS).
            // Usar como PK evita duplicidade e simplifica o UPSERT do admin.
            $table->string('kind', 32)->primary();
            $table->string('color_hex', 7);  // #RRGGBB
            $table->timestamps();
        });

        // Defaults: cores do mapa antigo. Quem não tocar mantém visual.
        $now = now();
        $defaults = [
            ['kind' => 'ligacao',     'color_hex' => '#ef4444'], // vermelho — Ligação
            ['kind' => 'whatsapp',    'color_hex' => '#eab308'], // amarelo  — WhatsApp
            ['kind' => 'email',       'color_hex' => '#2563eb'], // azul     — E-mail
            ['kind' => 'followup',    'color_hex' => '#8b5cf6'], // roxo     — Follow-up
            ['kind' => 'agendamento', 'color_hex' => '#f97316'], // laranja  — Agendamento
            ['kind' => 'visita',      'color_hex' => '#6b7280'], // cinza    — Visita Presencial
            ['kind' => 'reuniao',     'color_hex' => '#0ea5e9'], // ciano    — Reunião On-line
            ['kind' => 'anotacao',    'color_hex' => '#d97706'], // âmbar    — Anotação
            ['kind' => 'generica',    'color_hex' => '#9333ea'], // violeta  — Genérica
        ];
        foreach ($defaults as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        DB::table('task_kind_colors')->insert($defaults);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_kind_colors');
    }
};
