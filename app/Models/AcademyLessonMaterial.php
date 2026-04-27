<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyLessonMaterial extends Model
{
    protected $table = 'academy_lesson_materials';
    protected $fillable = ['lesson_id', 'name', 'file_path', 'file_size_bytes', 'mime_type'];
    protected $casts = ['file_size_bytes' => 'integer'];

    public function lesson()
    {
        return $this->belongsTo(AcademyLesson::class, 'lesson_id');
    }
}
