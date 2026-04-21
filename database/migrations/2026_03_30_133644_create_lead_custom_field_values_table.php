<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valores dos campos customizados preenchidos para cada lead.
 *
 * Cada linha = (lead, campo, valor). Um lead só pode ter 1 valor por campo
 * (UNIQUE), então pra atualizar usamos updateOrCreate no controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_custom_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
                ->constrained('leads')
                ->cascadeOnDelete();

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            // text() suporta de tudo: número, data ISO, JSON serializado de checkbox múltiplo, etc.
            // Conversão pro tipo certo é responsabilidade do model/controller.
            $table->text('value')->nullable();

            $table->timestamps();

            // Um lead só pode ter 1 valor por campo
            $table->unique(['lead_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_custom_field_values');
    }
};
