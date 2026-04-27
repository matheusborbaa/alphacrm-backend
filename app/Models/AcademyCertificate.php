<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyCertificate extends Model
{
    protected $table = 'academy_certificates';
    protected $fillable = ['user_id', 'course_id', 'certificate_number', 'issued_at', 'pdf_path'];
    protected $casts = ['issued_at' => 'datetime'];

    public function user()    { return $this->belongsTo(User::class); }
    public function course()  { return $this->belongsTo(AcademyCourse::class, 'course_id'); }
}
