<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

/**
 * Cria todas as permissions e roles do sistema, sincroniza roles com
 * as permissions corretas e migra os users existentes (que têm a coluna
 * `role` string) pra ter a role equivalente no spatie/permission.
 *
 * Idempotente: pode rodar quantas vezes quiser. Usa firstOrCreate
 * e syncPermissions.
 *
 * Matriz de permissões:
 *
 *   admin   — tudo (inclui users.assign_admin, única diferença pro gestor)
 *   gestor  — tudo exceto users.assign_admin
 *   corretor — só leads próprios + kanban ver + agenda própria
 */
class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpa o cache do spatie antes de mexer
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        /*
        |----------------------------------------------------------------------
        | 1) Catálogo de permissions
        |----------------------------------------------------------------------
        */
        $permissions = [
            // LEADS
            'leads.view_any',
            'leads.view_own',
            'leads.create',
            'leads.update_any',
            'leads.update_own',
            'leads.delete',
            'leads.move_any',
            'leads.move_own',

            // EMPREENDIMENTOS
            'empreendimentos.view',
            'empreendimentos.manage',
            'empreendimentos.field_definitions.manage',

            // CAMPOS CUSTOMIZADOS + REGRAS DE OBRIGATORIEDADE
            'custom_fields.manage',
            'status_required_fields.manage',

            // USUÁRIOS (CORRETORES)
            'users.view',
            'users.manage',
            'users.assign_admin',

            // DASHBOARD / RELATÓRIOS
            'dashboard.view',
            'reports.view',

            // KANBAN
            'kanban.view',
            'kanban.reorder',

            // AGENDA / APPOINTMENTS
            'appointments.view_any',
            'appointments.view_own',
            'appointments.manage_any',
            'appointments.manage_own',

            // NOTIFICAÇÕES
            'notifications.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        /*
        |----------------------------------------------------------------------
        | 2) Roles + permissions atribuídas
        |----------------------------------------------------------------------
        */
        $corretorPermissions = [
            'leads.view_own',
            'leads.create',
            'leads.update_own',
            'leads.move_own',
            'empreendimentos.view',
            'kanban.view',
            'appointments.view_own',
            'appointments.manage_own',
            'notifications.view',
        ];

        // Gestor = tudo do admin EXCETO users.assign_admin
        $gestorPermissions = array_values(array_diff($permissions, ['users.assign_admin']));

        // Admin = tudo
        $adminPermissions = $permissions;

        $this->syncRole('admin',    $guard, $adminPermissions);
        $this->syncRole('gestor',   $guard, $gestorPermissions);
        $this->syncRole('corretor', $guard, $corretorPermissions);

        /*
        |----------------------------------------------------------------------
        | 3) Sincroniza users existentes (coluna `role` string → role spatie)
        |----------------------------------------------------------------------
        | Se o user tem role='admin' na coluna antiga mas não tem a role
        | no spatie, atribui. Não remove roles já atribuídas.
        */
        User::whereNotNull('role')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $roleName = $user->role;

                if (!in_array($roleName, ['admin', 'gestor', 'corretor'])) {
                    continue;
                }

                if (!$user->hasRole($roleName)) {
                    $user->assignRole($roleName);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function syncRole(string $name, string $guard, array $perms): void
    {
        $role = Role::firstOrCreate([
            'name'       => $name,
            'guard_name' => $guard,
        ]);

        $role->syncPermissions($perms);
    }
}
