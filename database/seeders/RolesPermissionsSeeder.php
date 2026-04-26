<?php

namespace Database\Seeders;

use App\Models\User;
use App\Permissions\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        $catalogNames = Catalog::allNames();
        $legacyNames  = Catalog::legacyAll();
        $allToCreate  = array_unique(array_merge($catalogNames, $legacyNames));

        foreach ($allToCreate as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        $defaults       = Catalog::defaultsByType();
        $legacyDefaults = Catalog::legacyDefaultsByType();

        foreach (['admin', 'gestor', 'corretor'] as $name) {
            $role = Role::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);

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
