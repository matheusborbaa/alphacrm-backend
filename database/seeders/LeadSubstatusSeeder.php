<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;

/**
 * Popula os substatus do funil (lead_substatus).
 * Depende de LeadStatusSeeder ter rodado antes (pra os status existirem).
 *
 * Idempotente: usa updateOrCreate por (lead_status_id, name).
 * Busca o status pai pelo NOME — assim não depende do ID físico na tabela.
 */
class LeadSubstatusSeeder extends Seeder
{
    public function run(): void
    {
        $pipeline = [
            'Lead Cadastrado' => [
                'IA',
                'Aguardando GA',
                'Aguardando Atendimento Corretor',
                '1ª Tentativa de Contato',
                'Automação 1',
                '2ª Tentativa de Contato',
                'Automação 2',
            ],
            'Em Atendimento' => [
                'Sem Avanço (Frio)',
                'Conversando (Morno)',
                'Qualificado (Quente)',
            ],
            'Agendamento' => [
                'Aguardando Confirmação',
                'Confirmado',
                'Reagendou',
                'Não Compareceu',
            ],
            'Visita' => [
                'Consultoria Presencial Realizada',
                'Consultoria On-line Realizada',
            ],
            'Pasta' => [
                'Reunindo documentação',
                'Com Pendência',
                'Pasta em análise',
                'Aprovado',
                'Aprovado Condicionado',
                'Reprovado',
            ],
            'Em Negociação' => [
                'Montando Proposta',
                'Lead Analisando Proposta',
                'Ajustando Condição',
                'Proposta Aprovada',
                'Proposta Reprovada',
            ],
            'Venda' => [
                'Assinatura de Contrato',
                'Pagamento de Sinal',
                'Assinatura Banco',
                'Entrega de Chaves',
                'Distrato',
            ],
            // "Lead Descartado" não tem substatus — fica vazio de propósito.
        ];

        foreach ($pipeline as $statusName => $subs) {
            $status = LeadStatus::where('name', $statusName)->first();

            if (!$status) {
                $this->command?->warn("Status '{$statusName}' não encontrado — pulando substatus.");
                continue;
            }

            foreach ($subs as $index => $subName) {
                LeadSubstatus::updateOrCreate(
                    [
                        'lead_status_id' => $status->id,
                        'name'           => $subName,
                    ],
                    ['order' => $index + 1]
                );
            }
        }
    }
}
