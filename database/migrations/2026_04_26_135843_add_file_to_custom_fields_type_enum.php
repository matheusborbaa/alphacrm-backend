<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 'file' no ENUM da coluna `custom_fields.type`.
 *
 * O Laravel não tem syntax limpa pra estender ENUM (o doctrine não suporta
 * mexer em colunas ENUM via Schema::table), então usamos SQL raw com
 * `ALTER TABLE ... MODIFY COLUMN ...`. Tem que repetir TODOS os valores
 * que já existem + os novos.
 *
 * Sem esta migration, ao salvar um custom field do tipo 'file' o MySQL
 * silencia (com warning) e trunca a coluna pro default ('text'), causando
 * o erro `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type'`.
 *
 * Sintaxe MODIFY COLUMN é específica do MySQL/MariaDB. Se um dia migrar
 * pra Postgres/SQLite, repensar (Postgres usa ALTER TYPE; SQLite não tem
 * ENUM nativo).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Só roda em MySQL/MariaDB. Outros drivers tratam ENUM como string
        // — não há nada a fazer.
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("
            ALTER TABLE `custom_fields`
            MODIFY COLUMN `type` ENUM(
                'text',
                'textarea',
                'number',
                'date',
                'select',
                'checkbox',
                'file'
            ) NOT NULL DEFAULT 'text'
        ");
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Antes de tirar 'file' do enum, joga pra 'text' qualquer linha
        // que esteja usando o valor — senão o ALTER falha por integridade.
        // (Os arquivos uploadados ficam órfãos no storage; o admin terá
        // que limpar manualmente. Down é raro o suficiente pra isso.)
        DB::table('custom_fields')->where('type', 'file')->update(['type' => 'text']);

        DB::statement("
            ALTER TABLE `custom_fields`
            MODIFY COLUMN `type` ENUM(
                'text',
                'textarea',
                'number',
                'date',
                'select',
                'checkbox'
            ) NOT NULL DEFAULT 'text'
        ");
    }
};
