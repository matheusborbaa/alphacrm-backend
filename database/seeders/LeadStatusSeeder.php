<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeadStatus;

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
