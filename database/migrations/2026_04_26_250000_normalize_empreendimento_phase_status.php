<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Normaliza valores antigos de empreendimentos.status pros 5 slugs canônicos da timeline de fases.
return new class extends Migration {
    public function up(): void
    {
        $map = [
            'breve lançamento'    => 'breve_lancamento',
            'breve lancamento'    => 'breve_lancamento',
            'pré-lançamento'      => 'breve_lancamento',
            'pre-lancamento'      => 'breve_lancamento',
            'lançamento'          => 'lancamento',
            'em obras'            => 'em_obras',
            'obras'               => 'em_obras',
            'obras avançadas'     => 'obras_avancadas',
            'obras avancadas'     => 'obras_avancadas',
            'pronto'              => 'pronto_para_morar',
            'pronto morar'        => 'pronto_para_morar',
            'pronto_morar'        => 'pronto_para_morar',
            'pronto para morar'   => 'pronto_para_morar',
            'pronto pra morar'    => 'pronto_para_morar',
            'pronto_pra_morar'    => 'pronto_para_morar',
            'entregue'            => 'pronto_para_morar',
        ];


        foreach ($map as $from => $to) {
            DB::table('empreendimentos')
                ->whereRaw('LOWER(TRIM(status)) = ?', [$from])
                ->update(['status' => $to]);
        }
    }

    public function down(): void
    {

    }
};
