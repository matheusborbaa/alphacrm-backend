<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula pastas da Biblioteca de Mídia a um empreendimento (opcional)
 * pra escopo de acesso por seccionamento.
 *
 * Quando uma pasta tem `empreendimento_id` setado, só são listadas pra
 * usuários que têm acesso ao empreendimento via canAccessEmpreendimento().
 * Subpastas herdam o scope da raiz da árvore (resolvido pelo controller
 * pulando pra cima quando precisa).
 *
 * `is_system` marca pastas que são gerenciadas pelo backend (raiz
 * "EMPREENDIMENTOS" e as pastas criadas automaticamente por
 * empreendimento). Bloqueia delete via UI — só backend deleta quando o
 * empreendimento é removido.
 *
 * Use case: ao criar empreendimento "Reserva Vista Verde", o
 * EmpreendimentoObserver cria automaticamente:
 *   /EMPREENDIMENTOS/                  (is_system=1, empreendimento_id=null)
 *   /EMPREENDIMENTOS/Reserva Vista Verde/   (is_system=1, empreendimento_id=42)
 *
 * Aí gestor sobe materiais (book, plantas, fotos profissionais) dentro,
 * e só os corretores que atendem o "Reserva Vista Verde" enxergam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            // empreendimento_id NULL = pasta global / pessoal (comportamento legado).
            // nullOnDelete: se o empreendimento for apagado, mantém a pasta como
            // global em vez de cascadear (evita perda de arquivos por engano).
            $table->foreignId('empreendimento_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('empreendimentos')
                ->nullOnDelete();

            // Pasta gerenciada pelo sistema. Quando true, UI esconde "Excluir"
            // e o backend rejeita destroyFolder() pra esses ids.
            $table->boolean('is_system')
                ->default(false)
                ->after('description');

            $table->index('empreendimento_id');
            $table->index('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->dropForeign(['empreendimento_id']);
            $table->dropIndex(['empreendimento_id']);
            $table->dropIndex(['is_system']);
            $table->dropColumn(['empreendimento_id', 'is_system']);
        });
    }
};
