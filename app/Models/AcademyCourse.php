<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyCourse extends Model
{
    protected $table = 'academy_courses';
    protected $fillable = [
        'category_id', 'title', 'description', 'cover_image',
        'published', 'order', 'has_quiz', 'quiz_min_score',
        'certificate_enabled',
    ];
    protected $casts = [
        'published'           => 'boolean',
        'has_quiz'            => 'boolean',
        'certificate_enabled' => 'boolean',
        'order'               => 'integer',
        'quiz_min_score'      => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(AcademyCategory::class, 'category_id');
    }

    public function lessons()
    {
        return $this->hasMany(AcademyLesson::class, 'course_id')->orderBy('order');
    }

    public function quizQuestions()
    {
        return $this->hasMany(AcademyQuizQuestion::class, 'course_id')->orderBy('order');
    }
}
