<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademyCategory extends Model
{
    protected $table = 'academy_categories';
    protected $fillable = ['name', 'slug', 'color', 'order'];

    public function courses()
    {
        return $this->hasMany(AcademyCourse::class, 'category_id');
    }
}
