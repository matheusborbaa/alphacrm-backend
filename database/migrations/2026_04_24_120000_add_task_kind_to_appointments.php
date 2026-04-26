<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('task_kind', 40)
                ->nullable()
                ->after('type')
                ->comment('Subtipo da tarefa: ligacao|visita|anotacao|generica');

            $table->index('task_kind', 'appointments_task_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_task_kind_idx');
            $table->dropColumn('task_kind');
        });
    }
};
