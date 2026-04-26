<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint Cargos — fase 1
 * ---------------------------------------------------------------
 * Estende a tabela `roles` (Spatie) pra suportar cargos custom
 * criados pelo admin via UI, mantendo os 3 cargos base do sistema
 * (admin / gestor / corretor) protegidos.
 *
 * Colunas novas:
 *   - type        ENUM('admin','gestor','corretor')
 *                 Define a "personalidade" do cargo. Usado pelo
 *                 effectiveRole() pra continuar funcionando: cargos
 *                 customizados sempre herdam um type base, e o
 *                 frontend/middleware checa role base via type.
 *
 *   - is_system   BOOLEAN
 *                 true = cargo nativo (admin/gestor/corretor),
 *                 não pode ser editado nem deletado via UI.
 *                 false = cargo custom criado pelo admin.
 *
 *   - description TEXT NULL
 *                 Texto livre pra admin documentar pra que serve
 *                 o cargo. Aparece como tooltip na UI de cadastro.
 *
 * Backfill:
 *   - admin    → type=admin,    is_system=1
 *   - gestor   → type=gestor,   is_system=1
 *   - corretor → type=corretor, is_system=1
 *   - quaisquer outras roles existentes ficam type=NULL (precisam
 *     ser corrigidas via UI; o backend do Cargos vai exigir type
 *     ao salvar). is_system=0 pra todas as não-base.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // ENUM via raw porque o Doctrine não tem ENUM nativo —
            // se rodar em SQLite vira CHECK constraint mesmo, ok.
            $table->enum('type', ['admin', 'gestor', 'corretor'])
                ->nullable()
                ->after('name');

            $table->boolean('is_system')
                ->default(false)
                ->after('type');

            $table->text('description')
                ->nullable()
                ->after('is_system');
        });

        // Backfill dos 3 cargos base. Usa update por nome, não por id,
        // pra ser robusto a re-rodar (idempotente).
        DB::table('roles')->where('name', 'admin')
            ->update(['type' => 'admin', 'is_system' => true]);
        DB::table('roles')->where('name', 'gestor')
            ->update(['type' => 'gestor', 'is_system' => true]);
        DB::table('roles')->where('name', 'corretor')
            ->update(['type' => 'corretor', 'is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_system', 'description']);
        });
    }
};
