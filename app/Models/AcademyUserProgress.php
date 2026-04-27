<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyUserProgress extends Model
{
    protected $table = 'academy_user_progress';
    protected $fillable = [
        'user_id', 'lesson_id', 'watch_seconds', 'last_position_seconds',
        'started_at', 'completed_at', 'completed_via',
    ];
    protected $casts = [
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
        'watch_seconds'        => 'integer',
        'last_position_seconds'=> 'integer',
    ];

    public function lesson()
    {
        return $this->belongsTo(AcademyLesson::class, 'lesson_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
