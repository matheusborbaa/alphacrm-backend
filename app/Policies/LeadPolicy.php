<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, ['leads.view_any', 'leads.view_all'])
            || $user->can('leads.view_own')
            || $this->canAny($user, ['leads.view_team']);
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($this->canAny($user, ['leads.view_any', 'leads.view_all'])) {
            return true;
        }

        if ($user->can('leads.view_team') && $lead->assigned_user_id) {
            $teamIds = $user->descendantIds();
            if (in_array($lead->assigned_user_id, $teamIds, true)) {
                return true;
            }
        }
        return $user->can('leads.view_own')
            && $lead->assigned_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        if ($this->canAny($user, ['leads.update_any', 'leads.update_all'])) {
            return true;
        }
        return $user->can('leads.update_own')
            && $lead->assigned_user_id === $user->id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('leads.delete');
    }

    public function move(User $user, Lead $lead): bool
    {
        if ($this->canAny($user, ['leads.move_any', 'kanban.move_all'])) {
            return true;
        }
        if ($this->canAny($user, ['leads.move_own', 'kanban.move_own'])) {
            return $lead->assigned_user_id === $user->id;
        }
        return false;
    }

    public function interact(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    private function canAny(User $user, array $perms): bool
    {
        foreach ($perms as $p) {
            try {
                if ($user->can($p)) return true;
            } catch (\Throwable $e) {

            }
        }
        return false;
    }
}
