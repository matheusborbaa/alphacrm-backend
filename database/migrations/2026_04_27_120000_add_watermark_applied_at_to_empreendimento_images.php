<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E8 — Marca d'água nas imagens.
 * Coluna pra evitar reaplicar marca d'água em uma imagem que já tem
 * (evita "stacking" de logos quando o admin roda o comando retroativo
 * mais de uma vez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empreendimento_images', function (Blueprint $table) {
            $table->timestamp('watermark_applied_at')->nullable()->after('is_cover');
        });
    }

    public function down(): void
    {
        Schema::table('empreendimento_images', function (Blueprint $table) {
            $table->dropColumn('watermark_applied_at');
        });
    }
};
