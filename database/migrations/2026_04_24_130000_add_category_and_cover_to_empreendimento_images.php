<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
