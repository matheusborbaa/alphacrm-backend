<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estende a tabela `task_kind_colors` pra virar uma tabela completa de
 * tipos de tarefa configuráveis pelo admin (label, order, active, icon).
 * O nome da tabela é mantido pra preservar dados/cores já cadastradas.
 *
 * Tipos NOVOS criados pelo admin viram registros novos. Os 9 hardcoded
 * antigos ganham labels humanos. Adiciona "documentacao" como pedido do cliente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('task_kind_colors', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('kind');
            $table->string('icon', 40)->nullable()->after('color_hex');
            $table->unsignedInteger('order')->default(0)->after('icon');
            $table->boolean('active')->default(true)->after('order');
        });


        $defaults = [
            'ligacao'     => ['label' => 'Ligação',          'icon' => 'phone',            'order' => 10],
            'whatsapp'    => ['label' => 'WhatsApp',         'icon' => 'message-circle',   'order' => 20],
            'email'       => ['label' => 'E-mail',           'icon' => 'mail',             'order' => 30],
            'followup'    => ['label' => 'Follow-up',        'icon' => 'rotate-cw',        'order' => 40],
            'agendamento' => ['label' => 'Agendamento',      'icon' => 'calendar-plus',    'order' => 50],
            'visita'      => ['label' => 'Visita Presencial','icon' => 'map-pin',          'order' => 60],
            'reuniao'     => ['label' => 'Reunião On-line',  'icon' => 'video',            'order' => 70],
            'documentacao'=> ['label' => 'Documentação',     'icon' => 'file-text',        'order' => 80],
            'anotacao'    => ['label' => 'Anotação',         'icon' => 'sticky-note',      'order' => 90],
            'generica'    => ['label' => 'Genérica',         'icon' => 'circle-dashed',    'order' => 999],
        ];

        foreach ($defaults as $kind => $info) {
            $exists = DB::table('task_kind_colors')->where('kind', $kind)->exists();
            if ($exists) {
                DB::table('task_kind_colors')
                    ->where('kind', $kind)
                    ->update([
                        'label'      => $info['label'],
                        'icon'       => $info['icon'],
                        'order'      => $info['order'],
                        'active'     => true,
                        'updated_at' => now(),
                    ]);
            } else {

                DB::table('task_kind_colors')->insert([
                    'kind'       => $kind,
                    'label'      => $info['label'],
                    'color_hex'  => $kind === 'documentacao' ? '#06b6d4' : '#6b7280',
                    'icon'       => $info['icon'],
                    'order'      => $info['order'],
                    'active'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('task_kind_colors', function (Blueprint $table) {
            $table->dropColumn(['label', 'icon', 'order', 'active']);
        });
    }
};
