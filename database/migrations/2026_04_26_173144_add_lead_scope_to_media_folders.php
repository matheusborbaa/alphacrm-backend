<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint Biblioteca/Lead — vincula pastas da Biblioteca de Mídia a um lead
 * (opcional) pra escopo de acesso por corretor responsável.
 *
 * Espelha o que já fizemos pra empreendimento (`empreendimento_id`):
 *   - Pasta com lead_id setado só é listada pra:
 *     • admin/gestor (sempre)
 *     • corretor cujo `assigned_user_id` do lead é ele mesmo
 *   - Subpastas/arquivos herdam o scope (resolvido via
 *     effectiveLeadId() caminhando ancestrais)
 *   - Quando o lead é deletado, a pasta + arquivos físicos vão junto
 *     (LeadObserver::deleting → MediaLibrarySync::handleLeadDeleted)
 *
 * nullOnDelete: defesa extra. Se algo der errado no observer (exceção),
 * a FK não cascateia silenciosamente — vira pasta órfã e admin pode
 * decidir o que fazer.
 *
 * Use case: lead "Maria Silva" cadastrado → cria automaticamente
 *   /LEADS/Maria Silva (lead #42)/
 * Só o corretor responsável pelo Maria + admin/gestor enxergam.
 * Reatribuiu pra outro corretor → pasta passa a ser visível pro novo
 * (filtro lê assigned_user_id em runtime via JOIN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->foreignId('lead_id')
                ->nullable()
                ->after('empreendimento_id')
                ->constrained('leads')
                ->nullOnDelete();

            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_folders', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id']);
            $table->dropColumn('lead_id');
        });
    }
};
