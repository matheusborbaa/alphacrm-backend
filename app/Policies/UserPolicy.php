<?php

namespace App\Policies;

use App\Models\User;

/**
 * Policy de User — controla quem pode gerenciar corretores e admins.
 *
 * Sprint Cargos — aceita TANTO permissions legadas (`users.manage`) quanto
 * as novas granulares (`users.create`, `users.update`, `users.delete`).
 * Cargos system têm ambas; cargos custom recebem só as novas via UI.
 *
 * Regras chave:
 *  - viewAny/view  → users.view   (única, em ambos os mundos)
 *  - create        → users.create OU users.manage (legacy)
 *  - update        → users.update OU users.manage (legacy)
 *  - delete        → users.delete OU users.manage (legacy)
 *  - assign_admin  → única, sempre exigida pra mexer em admin
 */
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

        if (!$this->canAny($auth, ['users.delete', 'users.manage'])) {
            return false;
        }

        if ($target->hasRole('admin') && !$auth->can('users.assign_admin')) {
            return false;
        }

        return true;
    }

    /**
     * Mudar a role de um usuário.
     * Se a role alvo é "admin", precisa do privilégio extra.
     */
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

    /**
     * Helper: true se o user tem QUALQUER uma das permissions listadas.
     */
    private function canAny(User $auth, array $perms): bool
    {
        foreach ($perms as $p) {
            try {
                if ($auth->can($p)) return true;
            } catch (\Throwable $e) {
                // Permission não cadastrada — segue
            }
        }
        return false;
    }
}
