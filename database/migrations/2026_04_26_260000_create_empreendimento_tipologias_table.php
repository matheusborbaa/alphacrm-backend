<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tipologias múltiplas por empreendimento ("Apto 2 quartos", "Cobertura", "Área Privativa", etc).
// Schema flexível — só `name` é obrigatório, o resto é metadata pra filtros e UI rica.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('empreendimento_tipologias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empreendimento_id')
                  ->constrained('empreendimentos')
                  ->cascadeOnDelete();

            $table->string('name', 120);

            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('suites')->nullable();
            $table->decimal('area_min_m2', 8, 2)->nullable();
            $table->decimal('area_max_m2', 8, 2)->nullable();
            $table->decimal('price_from', 12, 2)->nullable();

            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['empreendimento_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empreendimento_tipologias');
    }
};
