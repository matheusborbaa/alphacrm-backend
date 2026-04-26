<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('empreendimento_field_values', function (Blueprint $table) {
    $table->id();

    $table->foreignId('empreendimento_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('field_definition_id')
        ->constrained('empreendimento_field_definitions')
        ->cascadeOnDelete();

    $table->text('value')->nullable();
    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('empreendimento_field_values');
    }
};
