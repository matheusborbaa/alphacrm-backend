<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

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
