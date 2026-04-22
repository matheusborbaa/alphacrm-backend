<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alinha o schema do `empreendimentos` com o validate do
 * EmpreendimentoController@store, que marca `commission_percentage` e
 * `code` como nullable.
 *
 * Motivo: Laravel 11+ aplica `ConvertEmptyStringsToNull` por padrão. Campo
 * vazio do form vira NULL antes do validate → o validate aceita → o
 * create() tenta inserir NULL → MySQL rejeita com SQLSTATE 23000 / 1048
 * ("Column X cannot be null"), resultando em HTTP 500 no POST
 * /empreendimentos.
 *
 * Schema original:
 *   - commission_percentage decimal(5,2) NOT NULL default 5
 *   - code string NOT NULL unique
 *
 * Requer doctrine/dbal pra change() em MySQL. Se não tiver, cai no
 * fallback com SQL bruto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('empreendimentos', 'commission_percentage')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    // Mantém precision/scale originais (5,2). Só remove NOT NULL
                    // e o default 5 (agora null é a ausência de comissão).
                    $table->decimal('commission_percentage', 5, 2)->nullable()->default(null)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `commission_percentage` DECIMAL(5,2) NULL DEFAULT NULL');
            }
        }

        if (Schema::hasColumn('empreendimentos', 'code')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    // `code` continua com UNIQUE (o change() preserva índices
                    // existentes); só vira nullable. MySQL aceita múltiplos
                    // NULLs em coluna unique.
                    $table->string('code')->nullable()->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `code` VARCHAR(255) NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empreendimentos', 'commission_percentage')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    $table->decimal('commission_percentage', 5, 2)->default(5)->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `commission_percentage` DECIMAL(5,2) NOT NULL DEFAULT 5');
            }
        }

        if (Schema::hasColumn('empreendimentos', 'code')) {
            try {
                Schema::table('empreendimentos', function (Blueprint $table) {
                    $table->string('code')->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                \DB::statement('ALTER TABLE `empreendimentos` MODIFY `code` VARCHAR(255) NOT NULL');
            }
        }
    }
};
