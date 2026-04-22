<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de documentos anexados a um lead (contrato, RG, comprovantes,
 * laudo, etc). Os bytes ficam em storage/app/private/leads/{leadId}/ —
 * só acessíveis pelo endpoint de download (LeadDocumentController@download),
 * que valida permissão antes de streamear.
 *
 * Fluxo de exclusão (decisão de produto): qualquer usuário autenticado
 * com acesso ao lead pode SOLICITAR exclusão, mas somente admin efetiva
 * o delete. Colunas deletion_requested_* carregam esse estado pendente.
 * Quando o admin aprova, removemos a row (hard delete) e o arquivo no disco.
 *
 * Auditoria: cada upload/request/approve/reject gera uma row em
 * lead_histories (LeadHistory types: document_upload, document_deletion_requested,
 * document_deleted, document_deletion_cancelled, document_deletion_rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->cascadeOnDelete();

            // Quem fez o upload (o autor original do arquivo no sistema).
            $table->foreignId('uploader_user_id')
                  ->constrained('users');

            // Nome que o usuário vê na UI (p.ex. "RG João Silva.pdf").
            $table->string('original_name', 255);

            // Caminho relativo dentro do disk 'local' (storage/app/).
            // Ex.: 'private/leads/42/2ab1...cde.pdf'
            $table->string('storage_path', 500);

            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Categoria livre (contrato, rg, comprovante, laudo, outros).
            // Não virou enum pra não travar evolução — frontend sugere valores.
            $table->string('category', 50)->nullable();
            $table->string('description', 500)->nullable();

            // Estado de solicitação de exclusão (nullable = sem pedido pendente).
            $table->foreignId('deletion_requested_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('deletion_reason', 500)->nullable();

            $table->timestamps();

            $table->index('lead_id');
            $table->index('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_documents');
    }
};
