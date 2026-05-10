<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


// Agrupa documentos em 3 famílias (cliente / negociacao / contrato) pra organizar a aba.
// category (string livre) continua existindo — document_group é só o "balde" macro.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->string('document_group', 20)->default('outros')->after('category');
            $table->index('document_group', 'idx_lead_documents_group');
        });


        // Backfill por heurística no category atual. O que não casar fica como "outros"
        // e o admin reclassifica na mão depois (botão "mover pra outro grupo" na UI).
        try {
            $patternMap = [
                'cliente' => [
                    'rg', 'cnh', 'cpf', 'certidao', 'certidão',
                    'holerite', 'extrato', 'ir', 'imposto',
                    'identidade', 'comprovante', 'renda', 'pessoal',
                ],
                'negociacao' => [
                    'ficha', 'atendimento', 'fechamento',
                    'proposta', 'simulacao', 'simulação',
                    'aprovacao', 'aprovação', 'negociacao', 'negociação',
                ],
                'contrato' => [
                    'contrato', 'ccv', 'aditivo', 'distrato',
                    'compra e venda', 'cpv',
                ],
            ];

            foreach ($patternMap as $group => $patterns) {
                foreach ($patterns as $p) {
                    DB::table('lead_documents')
                        ->whereRaw('LOWER(category) LIKE ?', ['%' . $p . '%'])
                        ->where('document_group', 'outros')
                        ->update(['document_group' => $group]);
                }
            }
        } catch (\Throwable $e) {

        }
    }

    public function down(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->dropIndex('idx_lead_documents_group');
            $table->dropColumn('document_group');
        });
    }
};
