<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_empreendimentos', function (Blueprint $table) {
    $table->id();

    $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    $table->foreignId('empreendimento_id')->constrained()->cascadeOnDelete();

    $table->integer('priority')->default(1);

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
