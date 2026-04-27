<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGoogleCredential extends Model
{
    protected $table = 'user_google_credentials';

    protected $fillable = [
        'user_id',
        'email',
        'access_token',
        'refresh_token',
        'scope',
        'expires_at',
        'calendar_id',
        'sync_token',
        'last_synced_at',
        'last_sync_error',
    ];

    protected $casts = [

        'access_token'   => 'encrypted',
        'refresh_token'  => 'encrypted',
        'expires_at'     => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
