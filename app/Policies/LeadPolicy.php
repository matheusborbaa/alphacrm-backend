<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Policy de Lead — usa permissions do spatie/permission.
 *
 * Sprint Cargos — agora aceita TANTO permissions legadas (`leads.view_any`,
 * `leads.update_any`, `leads.move_any`) quanto as novas equivalentes
 * (`leads.view_all`, `leads.update_all`, `kanban.move_all`). Cargos system
 * têm ambas (via seeder), cargos custom têm só as novas (via UI).
 *
 * Padrão "all/any vs own":
 *  - Tem any/all → pode em QUALQUER lead.
 *  - Tem só _own → pode SÓ se for o assigned_user_id do lead.
 *  - Senão → negado.
 */
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
        // view_team: subordinados na hierarquia (parent_user_id)
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

    /**
     * Mover lead no Kanban (mudar status_id).
     * Aceita legacy `leads.move_any/own` E nova `kanban.move_all/own`.
     */
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

    /**
     * Adicionar interação (ligação, whatsapp, etc) num lead.
     * Mesma regra de update.
     */
    public function interact(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    /**
     * Helper: true se o user tem QUALQUER uma das permissions listadas.
     * Usa loop ao invés de hasAnyPermission() do Spatie pra ser tolerante
     * a permission que não existe na tabela (silent false em vez de throw).
     */
    private function canAny(User $user, array $perms): bool
    {
        foreach ($perms as $p) {
            try {
                if ($user->can($p)) return true;
            } catch (\Throwable $e) {
                // Permission não cadastrada — segue
            }
        }
        return false;
    }
}
