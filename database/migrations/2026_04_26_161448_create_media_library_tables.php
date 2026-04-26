<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca de Mídia — pastas + arquivos hierárquicos.
 *
 * Casos de uso:
 *   - Admin/gestor sobe materiais de divulgação (PDFs de book, fotos
 *     de empreendimento, vídeos institucionais, contratos modelo).
 *   - Corretor entra na "Área do Corretor" → aba Biblioteca → navega
 *     pelas pastas, baixa o que precisa pra usar em redes sociais /
 *     enviar pro cliente.
 *
 * Diferente de:
 *   - lead_documents: aquilo é por LEAD (cliente específico).
 *   - empreendimento_images/documents: aquilo é por EMPREENDIMENTO.
 *   - chat_message_attachments: aquilo é vinculado a MENSAGEM de chat.
 *
 * Esta tabela é GLOBAL — material institucional/operacional reutilizável.
 *
 * Hierarquia:
 *   media_folders.parent_id (self-reference) → árvore de pastas
 *     parent_id NULL = pasta raiz (mostrada na home da biblioteca)
 *   media_files.folder_id → arquivo dentro de uma pasta (ou NULL = raiz)
 *
 * Permissions (ver Catalog.php "Biblioteca de Mídia"):
 *   media.view          → ler/baixar (corretor recebe por padrão)
 *   media.upload        → subir arquivo (gestor/admin)
 *   media.create_folder → criar pasta (gestor/admin)
 *   media.delete        → apagar pasta/arquivo (admin por padrão)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            // Self-reference pra permitir hierarquia (pasta dentro de pasta).
            // ON DELETE CASCADE: deletar pasta-mãe deleta filhas.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('media_folders')
                ->cascadeOnDelete();
            $table->string('name', 200);
            // Quem criou (pra audit). nullOnDelete: usuário deletado
            // não apaga a pasta.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('parent_id');
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            // Pasta onde está. NULL = raiz da biblioteca. CASCADE: pasta
            // apagada apaga arquivos junto (consistente com media_folders).
            $table->foreignId('folder_id')
                ->nullable()
                ->constrained('media_folders')
                ->cascadeOnDelete();
            // Nome de exibição (pode ser editado depois)
            $table->string('name', 200);
            // Nome ORIGINAL do upload (preservado pra download)
            $table->string('original_name', 255);
            // Caminho relativo no disco 'local' (storage/app/media/...)
            $table->string('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploader_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('media_folders');
    }
};
