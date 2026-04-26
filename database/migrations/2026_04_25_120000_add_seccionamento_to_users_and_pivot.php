<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->foreignId('parent_user_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('empreendimento_access_mode', ['all', 'specific'])
                ->default('all')
                ->after('parent_user_id');
        });

        Schema::create('user_empreendimentos', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('empreendimento_id')
                ->constrained('empreendimentos')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->primary(['user_id', 'empreendimento_id']);

            $table->index('empreendimento_id', 'user_emp_emp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_empreendimentos');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parent_user_id']);
            $table->dropColumn(['parent_user_id', 'empreendimento_access_mode']);
        });
    }
};
