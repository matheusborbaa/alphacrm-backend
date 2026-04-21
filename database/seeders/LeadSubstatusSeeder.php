<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
   use App\Models\LeadSubstatus;

class LeadSubstatusSeeder extends Seeder
{

public function run()
{
    $data = [

        1 => [ // Lead Cadastrado
            'IA',
            'Aguardando GA',
            'Aguardando Atendimento Corretor',
            '1ª Tentativa de Contato',
            'Automação 1',
            '2ª Tentativa de Contato',
            'Automação 2',
        ],

        2 => [ // Em Atendimento
            'Sem Avanço (Frio)',
            'Conversando (Morno)',
            'Qualificado (Quente)',
        ],

        3 => [ // Agendamento
            'Aguardando Confirmação',
            'Confirmado',
            'Reagendou',
            'Não Compareceu',
        ],

        4 => [ // Visita
            'Consultoria Presencial Realizada',
            'Consultoria On-line Realizada',
        ],

        5 => [ // Pasta
            'Reunindo documentação',
            'Com Pendência',
            'Pasta em análise',
            'Aprovado',
            'Aprovado Condicionado',
            'Reprovado',
        ],

        6 => [ // Em Negociação
            'Montando Proposta',
            'Lead Analisando Proposta',
            'Ajustando Condição',
            'Proposta Aprovada',
            'Proposta Reprovada',
        ],

        7 => [ // Venda
            'Assinatura de Contrato',
            'Pagamento de Sinal',
            'Assinatura Banco',
            'Entrega de Chaves',
            'Distrato',
        ],
    ];

    foreach ($data as $statusId => $subs) {
        foreach ($subs as $index => $name) {
            LeadSubstatus::create([
                'lead_status_id' => $statusId,
                'name' => $name,
                'order' => $index,
            ]);
        }
    }
}
}
