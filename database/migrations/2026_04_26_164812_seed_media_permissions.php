<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza as 4 permissions da Biblioteca de Mídia no banco e atribui
 * aos cargos system (admin/gestor/corretor) sem rodar o seeder inteiro.
 *
 * Por que migration ao invés de seeder: garante que ao subir o código,
 * `php artisan migrate` aplica automaticamente — sem depender de o
 * deploy lembrar de rodar `db:seed --class=RolesPermissionsSeeder`. Sem
 * essa sincronização, a aba Biblioteca da Área do Corretor pode parecer
 * vazia porque o admin não tem `media.view` no banco mesmo com o
 * Catalog atualizado.
 *
 * IDEMPOTENTE: usa firstOrCreate + givePermissionTo (que ignora se já
 * existe). Roda quantas vezes quiser sem dar conflito.
 *
 * Atribuição:
 *   admin    → media.view + media.upload + media.create_folder + media.delete
 *   gestor   → idem (cargo system, recebe tudo exceto bloqueados explícitos)
 *   corretor → media.view (somente leitura/download)
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $perms = [
            'media.view',
            'media.upload',
            'media.create_folder',
            'media.delete',
        ];

        // 1) Cria as 4 permissions se ainda não existem.
        foreach ($perms as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        // Limpa cache do Spatie pra givePermissionTo enxergar as novas.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2) Atribui aos cargos system. Usa firstOrCreate pra não falhar
        //    se algum cargo ainda não existir (deploy fresh).
        $admin    = Role::firstOrCreate(['name' => 'admin',    'guard_name' => $guard]);
        $gestor   = Role::firstOrCreate(['name' => 'gestor',   'guard_name' => $guard]);
        $corretor = Role::firstOrCreate(['name' => 'corretor', 'guard_name' => $guard]);

        // Admin e gestor recebem TUDO da biblioteca.
        foreach ($perms as $name) {
            if (!$admin->hasPermissionTo($name, $guard)) {
                $admin->givePermissionTo($name);
            }
            if (!$gestor->hasPermissionTo($name, $guard)) {
                $gestor->givePermissionTo($name);
            }
        }

        // Corretor só recebe view (consistente com o Catalog).
        if (!$corretor->hasPermissionTo('media.view', $guard)) {
            $corretor->givePermissionTo('media.view');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Não removemos as permissions no rollback — outros cargos custom
        // podem estar usando elas. Quem quiser limpar de verdade roda o
        // RolesPermissionsSeeder novamente após remover do Catalog.
    }
};
