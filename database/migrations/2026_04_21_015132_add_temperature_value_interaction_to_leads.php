<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos exigidos pela doc funcional:
 *  - temperature         : Quente / Morno / Frio (exibido na listagem e filtro)
 *  - value               : Valor do lead (aparece no card do kanban quando houver)
 *  - last_interaction_at : Última interação (define cor da borda no card: <=5d verde, 5-10d laranja, >10d vermelho)
 *  - status_changed_at   : Quando o lead entrou na etapa atual (tempo de ociosidade no card)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('temperature', ['frio', 'morno', 'quente'])
                  ->nullable()
                  ->after('sla_status')
                  ->index();

            $table->decimal('value', 12, 2)
                  ->nullable()
                  ->after('temperature');

            $table->timestamp('last_interaction_at')
                  ->nullable()
                  ->after('value');

            $table->timestamp('status_changed_at')
                  ->nullable()
                  ->after('last_interaction_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['temperature']);
            $table->dropColumn(['temperature', 'value', 'last_interaction_at', 'status_changed_at']);
        });
    }
};
