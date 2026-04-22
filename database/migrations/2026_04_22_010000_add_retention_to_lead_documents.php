<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona janela de retenção ("lixeira") em lead_documents.
 *
 * Fluxo novo (ativado via setting doc_retention_days + doc_deletion_requires_approval):
 *
 *   1. Request:       corretor solicita exclusão.
 *                     - se requires_approval=true: deletion_requested_at é setado e vai pra fila admin
 *                     - se requires_approval=false: soft-delete direto (pula pra etapa 2)
 *   2. Soft delete:   admin aprova (ou requires_approval=false) -> setamos:
 *                       deleted_at = now()
 *                       purge_at   = now() + doc_retention_days
 *                     O arquivo permanece no disco. A UI mostra o doc
 *                     riscado ("será excluído em X dias") + botão Restaurar (admin).
 *   3. Restore:       admin clica Restaurar dentro da janela:
 *                       deleted_at = null, purge_at = null, deletion_* = null.
 *   4. Hard delete:   job PurgeExpiredDocuments (scheduler daily) varre
 *                     registros com purge_at <= now(), apaga arquivo + row.
 *
 * Nota: usamos colunas próprias em vez do SoftDeletes trait do Laravel
 * pra manter o controle explícito de purge_at (janela de retenção).
 * Se eventualmente quisermos usar o trait, os nomes já são compatíveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('deletion_reason');
            $table->timestamp('purge_at')->nullable()->after('deleted_at');

            $table->index('deleted_at');
            $table->index('purge_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_documents', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['purge_at']);
            $table->dropColumn(['deleted_at', 'purge_at']);
        });
    }
};
