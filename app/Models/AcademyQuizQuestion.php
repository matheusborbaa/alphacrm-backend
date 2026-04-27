<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyQuizQuestion extends Model
{
    protected $table = 'academy_quiz_questions';
    protected $fillable = ['course_id', 'question', 'options', 'correct_index', 'order'];
    protected $casts = [
        'options'       => 'array',
        'correct_index' => 'integer',
        'order'         => 'integer',
    ];

    public function course()
    {
        return $this->belongsTo(AcademyCourse::class, 'course_id');
    }
}
