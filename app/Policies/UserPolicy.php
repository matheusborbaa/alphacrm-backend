<?php

namespace App\Policies;

use App\Models\User;

/**
 * Policy de User — controla quem pode gerenciar corretores e admins.
 *
 * Regras chave:
 *  - users.manage          → pode CRUD de usuários comuns
 *  - users.assign_admin    → pode promover/criar admin (só admin tem)
 *
 * Quem tem só users.manage NÃO pode mexer em outro admin.
 */
class UserPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->can('users.view') || $auth->can('users.manage');
    }

    public function view(User $auth, User $target): bool
    {
        return $auth->can('users.view') || $auth->can('users.manage');
    }

    public function create(User $auth): bool
    {
        return $auth->can('users.manage');
    }

    public function update(User $auth, User $target): bool
    {
        if (!$auth->can('users.manage')) {
            return false;
        }

        // Não pode mexer em admin sem o privilégio extra
        if ($target->hasRole('admin') && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }

    public function delete(User $auth, User $target): bool
    {
        // Ninguém pode se auto-deletar
        if ($auth->id === $target->id) {
            return false;
        }

        return $this->update($auth, $target);
    }

    /**
     * Mudar a role de um usuário.
     * Se a role alvo é "admin", precisa do privilégio extra.
     */
    public function assignRole(User $auth, User $target, string $newRole): bool
    {
        if (!$auth->can('users.manage')) {
            return false;
        }

        if ($newRole === 'admin' && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }
}
