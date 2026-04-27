<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyLesson extends Model
{
    protected $table = 'academy_lessons';
    protected $fillable = [
        'course_id', 'title', 'description', 'video_path',
        'duration_seconds', 'order',
    ];
    protected $casts = [
        'duration_seconds' => 'integer',
        'order'            => 'integer',
    ];

    public function course()
    {
        return $this->belongsTo(AcademyCourse::class, 'course_id');
    }

    public function materials()
    {
        return $this->hasMany(AcademyLessonMaterial::class, 'lesson_id');
    }
}
