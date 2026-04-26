<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $auth): bool
    {
        return $this->canAny($auth, ['users.view', 'users.manage']);
    }

    public function view(User $auth, User $target): bool
    {
        return $this->canAny($auth, ['users.view', 'users.manage']);
    }

    public function create(User $auth): bool
    {
        return $this->canAny($auth, ['users.create', 'users.manage']);
    }

    public function update(User $auth, User $target): bool
    {
        if (!$this->canAny($auth, ['users.update', 'users.manage'])) {
            return false;
        }

        if ($target->hasRole('admin') && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }

    public function delete(User $auth, User $target): bool
    {

        if ($auth->id === $target->id) {
            return false;
        }

        if (!$this->canAny($auth, ['users.delete', 'users.manage'])) {
            return false;
        }

        if ($target->hasRole('admin') && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }

    public function assignRole(User $auth, User $target, string $newRole): bool
    {
        if (!$this->canAny($auth, ['users.update', 'users.manage'])) {
            return false;
        }

        if ($newRole === 'admin' && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }

    private function canAny(User $auth, array $perms): bool
    {
        foreach ($perms as $p) {
            try {
                if ($auth->can($p)) return true;
            } catch (\Throwable $e) {

            }
        }
        return false;
    }
}
