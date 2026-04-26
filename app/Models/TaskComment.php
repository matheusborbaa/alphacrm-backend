<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
