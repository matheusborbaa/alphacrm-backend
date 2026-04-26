<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
      Schema::create('empreendimento_field_definitions', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('type');
    $table->string('unit')->nullable();
    $table->string('group')->nullable();
    $table->boolean('active')->default(true);
    $table->integer('order')->default(0);
    $table->timestamps();
});
    }

    public function down(): void
    {
        Schema::dropIfExists('empreendimento_field_definitions');
    }
};
