<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipologia_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tipologia_id')
                ->constrained('empreendimento_tipologias')
                ->cascadeOnDelete();

            $table->foreignId('field_definition_id')
                ->constrained('tipologia_field_definitions')
                ->cascadeOnDelete();

            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['tipologia_id', 'field_definition_id'], 'tipologia_field_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipologia_field_values');
    }
};
