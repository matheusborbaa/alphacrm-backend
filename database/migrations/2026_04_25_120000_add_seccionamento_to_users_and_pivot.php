<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint Seccionamento — Permissões por empreendimento + hierarquia gestor→corretor.
 *
 * Mudanças:
 *
 * 1. users.parent_user_id (FK self-ref, nullable)
 *    Liga corretor ao gestor responsável. Admin/gestor não tem parent
 *    (parent NULL = root). Cascata de permissão: gestor só pode dar pro
 *    subordinado o que ele mesmo tem (validado no controller).
 *
 * 2. users.empreendimento_access_mode ENUM('all','specific') DEFAULT 'all'
 *    Define como o user enxerga empreendimentos:
 *      - 'all'      = dinâmico, vê TODOS (inclusive criados depois). Default
 *                     pra preservar comportamento atual.
 *      - 'specific' = vê só os empreendimentos listados no pivot abaixo.
 *
 * 3. user_empreendimentos (pivot user_id, empreendimento_id)
 *    Lista explícita de empreendimentos que o user pode atender quando
 *    access_mode='specific'. Quando 'all', a pivot é ignorada.
 *    Cascade delete dos dois lados — limpa lixo automaticamente.
 *
 * Migração de dados: nada precisa ser feito. Default 'all' garante que
 * todos os users existentes continuem com acesso total (legado preservado).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FK auto-referenciada — corretor.parent_user_id aponta pro gestor.
            // nullOnDelete: se gestor é apagado, corretores ficam sem pai (root).
            // Não é cascade pra evitar deletar corretores em massa por engano.
            $table->foreignId('parent_user_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();

            // ENUM com 2 valores. Default 'all' preserva comportamento atual.
            $table->enum('empreendimento_access_mode', ['all', 'specific'])
                ->default('all')
                ->after('parent_user_id');
        });

        // Pivot user ↔ empreendimentos. Composite primary key evita
        // duplicatas (user X já tem empreendimento Y na lista).
        // Cascade dos dois lados pra não deixar registros órfãos.
        Schema::create('user_empreendimentos', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('empreendimento_id')
                ->constrained('empreendimentos')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->primary(['user_id', 'empreendimento_id']);
            // Index reverso pra "quais users podem atender empreendimento X"
            // (consulta usada no LeadAssignmentService).
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
