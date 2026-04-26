<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('user_metas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('ano');

            $table->unsignedInteger('meta_leads')->default(0);
            $table->unsignedInteger('meta_atendimentos')->default(0);
            $table->unsignedInteger('meta_vendas')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'mes', 'ano'], 'user_metas_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_metas');
    }
};
