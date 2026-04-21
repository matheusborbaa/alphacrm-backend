<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de campos customizados disponíveis pra usar em leads.
 *
 * Ex: o admin cria um campo "Motivo do descarte" do tipo "select" com
 * opções ["Preço", "Localização", "Outro"]. Esse campo fica disponível pra
 * ser exigido em algum status/substatus (via status_required_fields) e os
 * valores preenchidos pelos corretores ficam em lead_custom_field_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            // Rótulo exibido na UI
            $table->string('name');

            // Chave técnica única (vai virar o "name" do <input>)
            $table->string('slug')->unique();

            // Tipo do campo — define como renderiza no form
            $table->enum('type', [
                'text',
                'textarea',
                'number',
                'date',
                'select',
                'checkbox',
            ])->default('text');

            // Opções pra select/checkbox (JSON array de strings)
            $table->json('options')->nullable();

            // Pode desativar sem perder dados antigos
            $table->boolean('active')->default(true);

            // Ordem de exibição
            $table->integer('order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
