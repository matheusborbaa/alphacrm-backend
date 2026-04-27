<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyQuizAttempt extends Model
{
    protected $table = 'academy_quiz_attempts';
    protected $fillable = ['user_id', 'course_id', 'answers', 'score', 'passed', 'attempted_at'];
    protected $casts = [
        'answers'      => 'array',
        'score'        => 'integer',
        'passed'       => 'boolean',
        'attempted_at' => 'datetime',
    ];
}
