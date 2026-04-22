<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\OrphanLeadNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Distribui leads novos pelos corretores via rodízio simples.
 *
 * Regras atuais:
 *   - Só participa do rodízio quem é ativo (users.active = true),
 *     tem role 'corretor' E está com status_corretor = 'disponivel'.
 *     "Ocupado" e "offline" ficam de fora.
 *   - Ordem: quem foi atribuído há mais tempo recebe primeiro
 *     (orderBy last_lead_assigned_at ASC, NULL primeiro).
 *   - Se NINGUÉM está disponível, o lead vira "órfão": fica sem
 *     assigned_user_id e avisamos os admins. Quando um corretor
 *     voltar pro status 'disponivel', tryClaimNextOrphan() pega
 *     o órfão mais antigo e atribui pra ele automaticamente.
 *
 * Observação: o controle de disponibilidade persistente fica na
 * coluna users.status_corretor. O dropdown do sidebar no frontend
 * sincroniza isso via POST /users/me/status (UserController@updateStatus).
 */
class LeadAssignmentService
{
    /**
     * Atribui um lead ao próximo corretor disponível no rodízio.
     * Retorna o User escolhido, ou null se o lead ficou órfão
     * (nenhum corretor disponível).
     */
    public function assign(Lead $lead): ?User
    {
        $corretor = $this->pickNextAvailable();

        if (!$corretor) {
            $this->handleOrphan($lead);
            return null;
        }

        $lead->update([
            'assigned_user_id' => $corretor->id,
            'assigned_at'      => now(),
            'sla_deadline_at'  => now()->addMinutes(15),
            'sla_status'       => 'pending',
        ]);

        $corretor->update([
            'last_lead_assigned_at' => now(),
        ]);

        $this->notifyBroker($corretor, $lead);

        return $corretor;
    }

    /**
     * Quando um corretor fica 'disponivel', chama esse método pra tentar
     * pegar o lead órfão mais antigo e atribuir pra ele. Retorna o Lead
     * atribuído ou null se não há órfãos.
     *
     * Isso fecha a porta aberta deixada pelo handleOrphan() — assim que
     * alguém vira disponível, o sistema auto-distribui o que ficou pendente.
     */
    public function tryClaimNextOrphan(User $corretor): ?Lead
    {
        // Segurança: só reclama pra quem realmente pode receber lead.
        if (!$this->isEligible($corretor)) return null;

        // Transação + lock pra evitar que dois corretores virando 'disponivel'
        // ao mesmo tempo pipem o mesmo órfão.
        return DB::transaction(function () use ($corretor) {
            $lead = Lead::whereNull('assigned_user_id')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$lead) return null;

            $lead->update([
                'assigned_user_id' => $corretor->id,
                'assigned_at'      => now(),
                'sla_deadline_at'  => now()->addMinutes(15),
                'sla_status'       => 'pending',
            ]);

            $corretor->update([
                'last_lead_assigned_at' => now(),
            ]);

            $this->notifyBroker($corretor, $lead);

            return $lead->fresh();
        });
    }

    /* ==============================================================
     * INTERNOS
     * ============================================================== */

    /** Corretor ativo, role=corretor, status_corretor=disponivel? */
    private function isEligible(User $u): bool
    {
        return (bool) $u->active
            && strtolower((string) $u->role) === 'corretor'
            && strtolower((string) ($u->status_corretor ?? '')) === 'disponivel';
    }

    private function pickNextAvailable(): ?User
    {
        return User::where('role', 'corretor')
            ->where('active', true)
            ->where('status_corretor', 'disponivel') // ← filtro novo
            ->orderByRaw('last_lead_assigned_at IS NULL DESC')
            ->orderBy('last_lead_assigned_at', 'asc')
            ->first();
    }

    /**
     * Ninguém disponível pra receber. Lead fica sem corretor (órfão)
     * e os admins recebem uma notificação pra distribuir manualmente
     * ou aguardar um corretor voltar pra 'disponivel'.
     */
    private function handleOrphan(Lead $lead): void
    {
        // Zera dados de atribuição pra deixar claro que tá sem dono.
        $lead->update([
            'assigned_user_id' => null,
            'assigned_at'      => null,
            'sla_deadline_at'  => null,
            'sla_status'       => 'pending',
        ]);

        try {
            $admins = User::where('role', 'admin')
                ->where('active', true)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify(new OrphanLeadNotification($lead->fresh()));
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admins de lead órfão', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function notifyBroker(User $corretor, Lead $lead): void
    {
        try {
            $corretor->notify(new LeadAssignedNotification($lead->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar corretor de novo lead', [
                'lead_id' => $lead->id,
                'user_id' => $corretor->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
