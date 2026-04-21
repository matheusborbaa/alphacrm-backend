<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de obrigatoriedade: quais campos são obrigatórios quando um lead
 * entra num determinado status ou substatus.
 *
 * Cada linha amarra (status OU substatus) a (campo fixo do lead OU campo custom).
 *
 * Ex 1: "quando status='Visitou', o campo fixo 'empreendimento_id' é obrigatório"
 *   → lead_status_id=X, lead_column='empreendimento_id', required=true
 *
 * Ex 2: "quando substatus='Descartado por preço', o custom field 'motivo' é obrigatório"
 *   → lead_substatus_id=Y, custom_field_id=Z, required=true
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_required_fields', function (Blueprint $table) {
            $table->id();

            // AMARRAÇÃO: ou status ou substatus (nunca os dois).
            // Validado em nível de aplicação (controller) porque MySQL
            // não suporta CHECK constraint com OR bem em versões antigas.
            $table->foreignId('lead_status_id')
                ->nullable()
                ->constrained('lead_status')
                ->cascadeOnDelete();

            $table->foreignId('lead_substatus_id')
                ->nullable()
                ->constrained('lead_substatus')
                ->cascadeOnDelete();

            // CAMPO ALVO: ou coluna fixa do lead ou custom field (nunca os dois).
            // Se for coluna fixa, lead_column guarda o nome dela (ex: 'phone').
            // Se for custom, custom_field_id aponta pro registro em custom_fields.
            $table->string('lead_column')->nullable();

            $table->foreignId('custom_field_id')
                ->nullable()
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            // Sempre será true por enquanto — deixei aqui pra abrir caminho pra
            // "campo recomendado mas não obrigatório" sem nova migration.
            $table->boolean('required')->default(true);

            $table->timestamps();

            // Índices pra consulta rápida "dado um status, quais campos são obrigatórios"
            $table->index(['lead_status_id']);
            $table->index(['lead_substatus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_required_fields');
    }
};
