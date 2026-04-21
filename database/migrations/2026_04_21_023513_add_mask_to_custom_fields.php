<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `mask` em custom_fields.
 *
 * Guarda um preset textual ou um padrão customizado. Frontend interpreta:
 *   - presets conhecidos: cpf, cnpj, telefone, celular, data, cep, moeda
 *   - padrão literal: "000.000.000-00", "(00) 00000-0000", etc.
 *     (0 = digito, A = letra, * = qualquer caractere, demais = literal)
 *
 * null  = sem máscara (comportamento atual, preservado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->string('mask', 64)->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('mask');
        });
    }
};
