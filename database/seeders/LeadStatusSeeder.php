<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeadStatus;

/**
 * Popula as colunas principais do funil (lead_status).
 * Idempotente: usa updateOrCreate por nome, então roda em produção
 * sem duplicar ou quebrar IDs existentes.
 *
 * Ordem oficial do pipeline da Alpha Domus:
 *   1. Lead Cadastrado
 *   2. Em Atendimento
 *   3. Agendamento
 *   4. Visita
 *   5. Pasta
 *   6. Em Negociação
 *   7. Venda
 *   8. Lead Descartado
 */
class LeadStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            'Lead Cadastrado',
            'Em Atendimento',
            'Agendamento',
            'Visita',
            'Pasta',
            'Em Negociação',
            'Venda',
            'Lead Descartado',
        ];

        foreach ($statuses as $index => $name) {
            LeadStatus::updateOrCreate(
                ['name' => $name],
                ['order' => $index + 1]
            );
        }
    }
}
