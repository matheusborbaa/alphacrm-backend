<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E5.4 — Campos customizados de tipologia.
 *
 * Espelho de empreendimento_field_definitions. Admin pode cadastrar quantos
 * campos quiser por tipologia (ex: Quartos, Suítes, Vagas, Andar, Pé-direito,
 * Quintal m², Preço a partir de…) com tipo, ícone e unidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipologia_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->string('unit')->nullable();
            $table->string('group')->nullable();
            $table->string('icon', 64)->nullable();
            $table->json('options')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('required')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipologia_field_definitions');
    }
};
