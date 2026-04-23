<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela chat_message_attachments.
 *
 * Uma mensagem pode ter 0..N anexos. Cada anexo é de um dos 4 tipos:
 *   - lead           → referência a Lead (id na coluna attachable_id)
 *   - empreendimento → referência a Empreendimento
 *   - lead_document  → referência a LeadDocument (doc já salvo em um lead)
 *   - upload         → arquivo novo (imagem/PDF) em storage privado
 *
 * Por que polimórfico "manual" (type + attachable_id) em vez de morphTo padrão
 * do Laravel? Dois motivos:
 *   1. attachable_id é INTENCIONALMENTE sem FK — se o lead/doc/emp for
 *      deletado, o anexo continua existindo no histórico do chat. O snapshot
 *      preserva nome/capa/etc pra renderização.
 *   2. O tipo 'upload' não tem attachable_id — é arquivo standalone. Não
 *      encaixa no padrão morphTo tradicional.
 *
 * Campos de upload (storage_path, original_name, mime_type, size_bytes) ficam
 * nullable e só são preenchidos quando type='upload'. Poderia estar em tabela
 * separada mas a cardinalidade baixa e join extra não compensam.
 *
 * snapshot (json): frozen metadata no momento do attach. Ex:
 *   - lead: {"name":"Fulano Silva","etapa":"Negociação"}
 *   - empreendimento: {"name":"Residencial X","cover_image":"/storage/.."}
 *   - lead_document: {"original_name":"contrato.pdf","size_bytes":123456}
 *   - upload: igual ao documento (redundante com as colunas, mas unifica render)
 *
 * Índice (message_id, id): query principal é "dá todos os anexos da msg X".
 * Sem índice em attachable_id — não fazemos lookup reverso ("em quantas
 * mensagens esse lead aparece?"). Se virar necessidade, adiciona depois.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            // Enum manual — simpler que DB enum type (migrations chatas de mexer).
            // Validação real fica no controller.
            $table->string('type', 32); // 'lead'|'empreendimento'|'lead_document'|'upload'

            // Sem FK: preserva histórico se o recurso source sumir.
            $table->unsignedBigInteger('attachable_id')->nullable();

            // Apenas para type='upload'.
            $table->string('storage_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Quem fez o upload (pra auditoria). Null pros tipos que não são upload.
            $table->foreignId('uploader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // JSON com metadados congelados — resiliente a deleção da source.
            $table->json('snapshot')->nullable();

            $table->timestamps();

            $table->index(['message_id', 'id'], 'chat_msg_att_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_attachments');
    }
};
