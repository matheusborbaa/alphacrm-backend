<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona os campos exigidos pela doc funcional na listagem de
 * empreendimentos (metragem, preço inicial). Campos status/tipo/finalidade
 * já foram criados por migration anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {
            if (!Schema::hasColumn('empreendimentos', 'metragem')) {
                $table->decimal('metragem', 10, 2)->nullable()->after('status');
            }
            if (!Schema::hasColumn('empreendimentos', 'initial_price')) {
                $table->decimal('initial_price', 14, 2)->nullable()->after('metragem');
            }
        });
    }

    public function down(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {
            if (Schema::hasColumn('empreendimentos', 'initial_price')) {
                $table->dropColumn('initial_price');
            }
            if (Schema::hasColumn('empreendimentos', 'metragem')) {
                $table->dropColumn('metragem');
            }
        });
    }
};
