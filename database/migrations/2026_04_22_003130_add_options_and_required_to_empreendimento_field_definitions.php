<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona suporte a:
 *  - options (JSON nullable): lista de opções pra campos do tipo 'select'
 *    (ex: ["Norte", "Sul", "Leste", "Oeste"] pra orientação solar).
 *  - required (boolean, default false): define se o campo é obrigatório
 *    no cadastro/edição de empreendimento.
 *
 * A coluna `type` continua como string (não-enum) pra aceitar os novos
 * tipos sem precisar de nova migration: counter (contador ±), boolean
 * (toggle tem/não tem), text/number (livre), select (options).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimento_field_definitions', function (Blueprint $table) {
            $table->json('options')->nullable()->after('group');
            $table->boolean('required')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('empreendimento_field_definitions', function (Blueprint $table) {
            $table->dropColumn(['options', 'required']);
        });
    }
};
