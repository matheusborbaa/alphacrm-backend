<?php

namespace App\Observers;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\LeadHistory;
use App\Models\Commission;
use App\Models\Setting;
use App\Services\AuditService;

class LeadObserver
{
    /**
     * Dispara sempre que um Lead é atualizado.
     *
     * Responsabilidades:
     *   1. Registrar em lead_histories toda vez que status OU substatus muda
     *      (usado pela aba "Histórico" do lead.php).
     *   2. Criar comissão quando o lead é marcado como "Vendido".
     *
     * Rodar tudo aqui em um único observer garante que qualquer caminho
     * que chame $lead->update(...) — LeadController@update, KanbanController@move,
     * imports futuros, testes — capture o histórico sem depender de cada
     * controller se lembrar de gravar manualmente.
     */
    public function updated(Lead $lead): void
    {
        $this->logStatusHistory($lead);
        $this->logSubstatusHistory($lead);
        $this->maybeCreateCommission($lead);
    }

    /**
     * Grava uma entrada de histórico quando o status principal muda.
     *
     * Exemplo de resultado no banco:
     *   type='status_change', from='Em Atendimento', to='Visita Agendada',
     *   description='Status alterado: Em Atendimento → Visita Agendada'
     */
    protected function logStatusHistory(Lead $lead): void
    {
        if (!$lead->wasChanged('status_id')) {
            return;
        }

        $fromId = $lead->getOriginal('status_id');
        $toId   = $lead->status_id;

        $fromName = $fromId ? LeadStatus::whereKey($fromId)->value('name') : null;
        $toName   = $toId   ? LeadStatus::whereKey($toId)->value('name')   : null;

        // Evita ruído se ambos forem null (não deveria acontecer, mas defesa extra)
        if (!$fromName && !$toName) {
            return;
        }

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => auth()->id(),
            'type'        => 'status_change',
            'from'        => $fromName,
            'to'          => $toName,
            'description' => sprintf(
                'Status alterado: %s → %s',
                $fromName ?? '—',
                $toName   ?? '—'
            ),
        ]);
    }

    /**
     * Grava uma entrada de histórico quando o substatus muda.
     *
     * Observação: quando o usuário move um card entre STATUS diferentes
     * no kanban, tanto status quanto substatus costumam mudar ao mesmo
     * tempo. Nesse caso teremos DUAS entradas — uma de status_change e
     * outra de substatus_change — o que é o comportamento desejado (o
     * histórico fica granular).
     */
    protected function logSubstatusHistory(Lead $lead): void
    {
        if (!$lead->wasChanged('lead_substatus_id')) {
            return;
        }

        $fromId = $lead->getOriginal('lead_substatus_id');
        $toId   = $lead->lead_substatus_id;

        $fromName = $fromId ? LeadSubstatus::whereKey($fromId)->value('name') : null;
        $toName   = $toId   ? LeadSubstatus::whereKey($toId)->value('name')   : null;

        // Se os dois estão null é porque o lead nunca teve subetapa e continua
        // sem — nada pra registrar.
        if (!$fromName && !$toName) {
            return;
        }

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => auth()->id(),
            'type'        => 'substatus_change',
            'from'        => $fromName,
            'to'          => $toName,
            'description' => sprintf(
                'Etapa alterada: %s → %s',
                $fromName ?? 'Sem etapa',
                $toName   ?? 'Sem etapa'
            ),
        ]);
    }

    /**
     * Cria comissão (em rascunho) quando o lead entra em um status OU
     * substatus configurado como gatilho em /configuracoes.php → Geral.
     *
     * Sprint 3.7e — antes era hardcoded pro nome "Vendido". Agora o admin
     * escolhe quais IDs disparam. A regra:
     *
     *   - Se `commission_trigger_status_ids` tem algum ID: checa se o
     *     NOVO status_id do lead está nessa lista.
     *   - Se `commission_trigger_substatus_ids` tem algum ID: checa se o
     *     NOVO lead_substatus_id está nessa lista.
     *   - Qualquer um dos dois matchando → dispara.
     *   - Se AMBAS as listas estão vazias: cai no fallback legado
     *     (compara nome do status com "Vendido") — garante que quem tá
     *     rodando o CRM sem ter configurado nada continua funcionando.
     *
     * O trigger roda em updated(), então checamos wasChanged() pra não
     * disparar em updates que não mexem em status nem substatus (ex:
     * alterou o telefone do lead).
     */
    protected function maybeCreateCommission(Lead $lead): void
    {
        $changedStatus    = $lead->wasChanged('status_id');
        $changedSubstatus = $lead->wasChanged('lead_substatus_id');

        if (!$changedStatus && !$changedSubstatus) {
            return;
        }

        // Idempotência — se já existe comissão pro lead, não cria outra.
        // (O controller tem um endpoint de cancelamento + recriação se
        // precisar refazer.)
        if (Commission::where('lead_id', $lead->id)->exists()) {
            return;
        }

        if (!$this->matchesCommissionTrigger($lead, $changedStatus, $changedSubstatus)) {
            return;
        }

        $percentage = $lead->empreendimento
            ? $lead->empreendimento->commission_percentage
            : 5;

        $saleValue = $lead->sale_value
            ?? $lead->empreendimento?->average_sale_value
            ?? 0;

        $commissionValue = ($saleValue * $percentage) / 100;

        // Sprint 3.7a — comissão nasce como `draft` (provisória). O
        // gestor/financeiro precisa confirmar a venda em /comissoes.php
        // pra ela virar `pending` e gerar o lançamento financeiro. Assim
        // venda desfeita não polui relatórios com comissão fantasma.
        Commission::create([
            'lead_id'               => $lead->id,
            'user_id'               => $lead->assigned_user_id,
            'sale_value'            => $saleValue,
            'commission_percentage' => $percentage,
            'commission_value'      => $commissionValue,
            'status'                => Commission::STATUS_DRAFT,
        ]);

        AuditService::log(
            'commission_created',
            'Lead',
            $lead->id,
            $lead->assigned_user_id,
            null,
            [
                'commission_percentage' => $percentage,
                'commission_value'      => $commissionValue,
            ],
            'observer'
        );
    }

    /**
     * True se o lead acabou de entrar num status/substatus configurado
     * como gatilho. Fallback pro nome "Vendido" se admin ainda não
     * configurou nada.
     *
     * @param  bool  $changedStatus     lead->wasChanged('status_id')
     * @param  bool  $changedSubstatus  lead->wasChanged('lead_substatus_id')
     */
    protected function matchesCommissionTrigger(Lead $lead, bool $changedStatus, bool $changedSubstatus): bool
    {
        $statusIds    = array_map('intval', (array) Setting::get('commission_trigger_status_ids', []));
        $substatusIds = array_map('intval', (array) Setting::get('commission_trigger_substatus_ids', []));

        // Fallback legado — nenhum gatilho configurado = regra "Vendido"
        // original. Só dispara na MUDANÇA de status (não de substatus).
        if (empty($statusIds) && empty($substatusIds)) {
            return $changedStatus && $lead->status?->name === 'Vendido';
        }

        // Match por status: só dispara quando acabou de mudar PRA esse
        // status — evita refirar se outro campo foi editado depois.
        if ($changedStatus
            && $lead->status_id !== null
            && in_array((int) $lead->status_id, $statusIds, true)
        ) {
            return true;
        }

        // Match por substatus: dispara quando acabou de mudar PRA esse
        // substatus. Cobre o caso "venda confirmada" ser uma subetapa
        // dentro de um status genérico ("Negociação" → "Contrato assinado").
        if ($changedSubstatus
            && $lead->lead_substatus_id !== null
            && in_array((int) $lead->lead_substatus_id, $substatusIds, true)
        ) {
            return true;
        }

        return false;
    }
}
