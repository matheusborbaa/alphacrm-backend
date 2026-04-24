<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.8c — imagens categorizadas + capa.
 *
 *   category : 'imagens' (default) | 'plantas' | 'decorado'
 *              determina qual aba da galeria mostra a imagem.
 *   is_cover : exatamente UMA por empreendimento deveria ter true.
 *              O controller garante a exclusividade ao setar.
 *
 * A cover_image (coluna string na tabela empreendimentos) continua
 * funcionando como fallback — quando is_cover é marcado, o controller
 * também atualiza empreendimentos.cover_image pra manter compat com
 * listagens/cards antigos que leem direto do empreendimento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimento_images', function (Blueprint $table) {
            $table->string('category', 20)
                ->default('imagens')
                ->after('image_path')
                ->comment('imagens | plantas | decorado');

            $table->boolean('is_cover')
                ->default(false)
                ->after('category');

            $table->index(['empreendimento_id', 'category'], 'emp_imgs_cat_idx');
            $table->index(['empreendimento_id', 'is_cover'], 'emp_imgs_cover_idx');
        });
    }

    public function down(): void
    {
        Schema::table('empreendimento_images', function (Blueprint $table) {
            $table->dropIndex('emp_imgs_cat_idx');
            $table->dropIndex('emp_imgs_cover_idx');
            $table->dropColumn(['category', 'is_cover']);
        });
    }
};
