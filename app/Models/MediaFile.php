<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFile extends Model
{
    protected $fillable = [
        'folder_id',
        'name',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'uploader_user_id',
        'description',
        'category',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}
