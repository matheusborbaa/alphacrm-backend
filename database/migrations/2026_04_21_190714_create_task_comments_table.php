<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela de comentários de tarefa.
 *
 * Cenário: corretor recebe uma tarefa atribuída por um gestor — ele
 * não pode EDITAR os campos (título, prazo, prioridade) mas precisa
 * poder comentar ("Cliente remarcou pra sexta", "Documento pendente
 * no cartório", etc) e marcar como concluída.
 *
 * task_id referencia appointments (já que uma tarefa é um Appointment
 * com type='task'). ON DELETE CASCADE remove comments órfãos quando
 * a tarefa é excluída.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                  ->constrained('appointments')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users');
            $table->text('body');
            $table->timestamps();

            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
