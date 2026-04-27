<?php

namespace Database\Seeders;

use App\Models\LeadChannel;
use App\Models\LeadSource;
use Illuminate\Database\Seeder;

/**
 * Seed das listas pré-definidas de Origem e Canal de leads.
 * Idempotente — pode ser rodado várias vezes sem duplicar.
 *
 * Origem (quem trouxe o lead) — 5 valores fixos
 * Canal  (por onde o lead entrou) — 18 valores fixos
 *
 * Listas vieram do PDF de solicitações do cliente (item L7).
 */
class LeadSourceChannelSeeder extends Seeder
{
    public function run(): void
    {

        $sources = [
            'Imobiliária',
            'Gerente',
            'Corretor',
            'Construtora',
            'Outro',
        ];

        foreach ($sources as $name) {
            LeadSource::firstOrCreate(['name' => $name]);
        }


        $channels = [
            'Meta Ads',
            'Google Ads',
            'TikTok Ads',
            'Orgânico Instagram',
            'Orgânico Facebook',
            'Orgânico TikTok',
            'WhatsApp',
            'Site',
            'Formulário',
            'Portal Imobiliário',
            'Indicação',
            'Base / Lista',
            'Contato Pessoal',
            'Automação',
            'Panfletagem',
            'Espontâneo',
            'Parceria',
            'Evento',
        ];

        foreach ($channels as $name) {
            LeadChannel::firstOrCreate(['name' => $name]);
        }
    }
}
