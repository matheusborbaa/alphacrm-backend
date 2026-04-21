<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TaskComment — um comentário em uma tarefa (Appointment com type='task').
 *
 * Qualquer usuário que possa VISUALIZAR a tarefa pode comentar. Isso
 * inclui:
 *   - admin / gestor (veem tudo)
 *   - dono da tarefa (user_id)
 *   - qualquer corretor se scope='company'
 *
 * A regra de quem pode *excluir* um comentário fica no controller:
 *   - autor do comentário
 *   - admin / gestor
 */
class TaskComment extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'body',
    ];

    public function task()
    {
        return $this->belongsTo(Appointment::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
