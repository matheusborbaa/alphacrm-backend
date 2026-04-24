<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.8d — documentos fixos no empreendimento.
 *
 * 2 slots fixos (por simplicidade; se crescer a demanda depois a gente
 * migra pra uma tabela empreendimento_documents com tipo):
 *   - book_path         : PDF do "Book do Empreendimento"
 *   - price_table_path  : PDF da "Tabela de Valores"
 *
 * Os timestamps permitem mostrar "Atualizado há X dias" nos cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {
            $table->string('book_path')->nullable()->after('cover_image');
            $table->timestamp('book_uploaded_at')->nullable()->after('book_path');

            $table->string('price_table_path')->nullable()->after('book_uploaded_at');
            $table->timestamp('price_table_uploaded_at')->nullable()->after('price_table_path');
        });
    }

    public function down(): void
    {
        Schema::table('empreendimentos', function (Blueprint $table) {
            $table->dropColumn([
                'book_path',
                'book_uploaded_at',
                'price_table_path',
                'price_table_uploaded_at',
            ]);
        });
    }
};
