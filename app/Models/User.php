<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'last_lead_assigned_at',
        'avatar',
        // Usado pelo rodízio: UserController@updateStatus grava aqui via
        // $user->update(['status_corretor' => ...]). Sem estar em $fillable,
        // Mass Assignment Protection do Eloquent IGNORA SILENCIOSAMENTE e
        // o DB nunca persiste a mudança (bug: select do corretor "voltava"
        // pra offline sempre que a home recarregava).
        'status_corretor',
    ];

   
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
	
	
	 public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(LeadInteraction::class);
    }
	
	
	
	
	
}
