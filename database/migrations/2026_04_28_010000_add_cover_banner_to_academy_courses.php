<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Banner horizontal (faixa fina ~80px de altura) que aparece no topo da página do curso.
// Separado de cover_image (thumb quadrado do catálogo) porque a proporção é totalmente diferente.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->string('cover_banner')->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('academy_courses', function (Blueprint $table) {
            $table->dropColumn('cover_banner');
        });
    }
};
