<?php

namespace Database\Seeders;

use App\Models\User;
use App\Permissions\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint Cargos — seeder reescrito sobre o Catalog.
 * ---------------------------------------------------------------
 * O QUE FAZ:
 *   1. Sincroniza a tabela `permissions` com TODAS as permissions do
 *      Catalog (cria as novas, mantém existentes — não remove pra
 *      não quebrar cargos custom que possam estar usando algo
 *      removido em alguma alteração futura do catalog).
 *
 *   2. Cria/atualiza os 3 cargos SYSTEM (admin, gestor, corretor),
 *      marca is_system=1 e type=name, e sincroniza as permissions
 *      conforme defaultsByType() do Catalog.
 *
 *   3. Migra users existentes que têm coluna `role` mas não têm a
 *      role no Spatie (cobertura pra contas legadas).
 *
 * IDEMPOTENTE: pode rodar quantas vezes quiser. Não toca em cargos
 * custom (is_system=0) — esses são gerenciados via UI.
 *
 * IMPORTANTE: Os cargos system continuam SOBRESCREVENDO suas
 * permissions a cada run. Isso é intencional — quando você adiciona
 * uma permission nova ao Catalog, rodar o seeder garante que admin/
 * gestor recebam o default automaticamente.
 */
class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        // 1) Sincroniza catálogo com a tabela permissions (cria as faltantes).
        //    Inclui também as legacy pra preservar compat com middlewares
        //    espalhados pelo backend que ainda checam permissions antigas
        //    (cleanup futuro: refatorar essas checagens, remover legacyAll).
        $catalogNames = Catalog::allNames();
        $legacyNames  = Catalog::legacyAll();
        $allToCreate  = array_unique(array_merge($catalogNames, $legacyNames));

        foreach ($allToCreate as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        // 2) Cargos system — atualiza permissions e flags.
        //    Mescla defaults novos (Catalog) + legacy do mesmo type, pra
        //    garantir que admin/gestor/corretor não percam acesso a nada
        //    que dependia das permissions antigas.
        $defaults       = Catalog::defaultsByType();
        $legacyDefaults = Catalog::legacyDefaultsByType();

        foreach (['admin', 'gestor', 'corretor'] as $name) {
            $role = Role::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);

            // Força flags system na role (caso esteja rodando antes da migration
            // ter aplicado o backfill, ou se algum admin curioso desmarcou via DB).
            // Os campos só existem se a migration 2026_04_25_140000 já rodou.
            if (Role::query()->getConnection()->getSchemaBuilder()->hasColumn('roles', 'is_system')) {
                DB::table('roles')->where('id', $role->id)->update([
                    'type'      => $name,
                    'is_system' => true,
                ]);
            }

            $merged = array_unique(array_merge(
                $defaults[$name] ?? [],
                $legacyDefaults[$name] ?? []
            ));
            $role->syncPermissions($merged);
        }

        // 3) Migra users legados (coluna role string → spatie)
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
}
