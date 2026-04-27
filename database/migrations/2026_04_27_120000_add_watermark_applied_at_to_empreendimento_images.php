<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coluna pra marcar quando o watermark foi aplicado. Sem isso, rodar o comando retroativo duas vezes
// empilha o logo. Com isso, ele pula as que já têm.
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
