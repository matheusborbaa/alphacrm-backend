<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Policy de Lead — usa permissions do spatie/permission.
 *
 * Padrão "any vs own":
 *  - Se o user tem leads.X_any  → pode em QUALQUER lead.
 *  - Se tem leads.X_own         → pode SÓ se for o assigned_user_id do lead.
 *  - Senão → negado.
 */
class LeadPolicy
{
    /**
     * Listagem geral (LeadController@index).
     * Quem só tem _own também passa, e o controller filtra pra mostrar
     * apenas os próprios.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('leads.view_any')
            || $user->can('leads.view_own');
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->can('leads.view_any')) {
            return true;
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
        if ($user->can('leads.update_any')) {
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
     * Trata como variação do update — se pode editar, pode mover.
     */
    public function move(User $user, Lead $lead): bool
    {
        if ($user->can('leads.move_any')) {
            return true;
        }

        return $user->can('leads.move_own')
            && $lead->assigned_user_id === $user->id;
    }

    /**
     * Adicionar interação (ligação, whatsapp, etc) num lead.
     * Mesma regra de update.
     */
    public function interact(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }
}
