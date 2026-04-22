<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `is_sensitive` em custom_fields.
 *
 * Marca campos que guardam PII (dado pessoal sensível conforme LGPD — CPF,
 * RG, renda, etc.) pra o frontend mascarar por padrão em listagens e
 * histórico, e pra o backend exigir passagem pelo endpoint /leads/{id}/reveal
 * quando o valor cleartext for necessário (que, por sua vez, loga o acesso
 * em lead_histories).
 *
 * Default false — comportamento preservado pros campos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false)->after('mask');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('is_sensitive');
        });
    }
};
