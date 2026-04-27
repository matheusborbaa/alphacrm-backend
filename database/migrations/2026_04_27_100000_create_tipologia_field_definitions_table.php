<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Definições dos campos custom da tipologia. Mesma forma do empreendimento_field_definitions
// só que aplicado por tipologia (Quartos, Suítes, Vagas, Andar, Pé-direito, etc).
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
