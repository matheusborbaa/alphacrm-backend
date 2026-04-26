<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_kind_colors', function (Blueprint $table) {

            $table->string('kind', 32)->primary();
            $table->string('color_hex', 7);
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['kind' => 'ligacao',     'color_hex' => '#ef4444'],
            ['kind' => 'whatsapp',    'color_hex' => '#eab308'],
            ['kind' => 'email',       'color_hex' => '#2563eb'],
            ['kind' => 'followup',    'color_hex' => '#8b5cf6'],
            ['kind' => 'agendamento', 'color_hex' => '#f97316'],
            ['kind' => 'visita',      'color_hex' => '#6b7280'],
            ['kind' => 'reuniao',     'color_hex' => '#0ea5e9'],
            ['kind' => 'anotacao',    'color_hex' => '#d97706'],
            ['kind' => 'generica',    'color_hex' => '#9333ea'],
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
