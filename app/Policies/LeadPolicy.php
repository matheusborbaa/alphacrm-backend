<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {

      \Log::info('POLICY CHECK', [
        'auth_user' => $user->id,
        'lead_assigned' => $lead->assigned_user_id,
        'role' => $user->role
    ]);
        if (in_array($user->role, ['admin', 'gestor'])) {
            return true;
        }

        return $lead->assigned_user_id == $user->id;
    }

    public function update(User $user, Lead $lead): bool
    {
        if (in_array($user->role, ['admin', 'gestor'])) {
            return true;
        }

        return $lead->assigned_user_id === $user->id;
    }
    public function interact(User $user, Lead $lead): bool
{
    // admin e gestor podem tudo
    if(in_array($user->role, ['admin','gestor'])){
        return true;
    }

    // corretor só no próprio lead
    return $lead->assigned_user_id === $user->id;
}
}
