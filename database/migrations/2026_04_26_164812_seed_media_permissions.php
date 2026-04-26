<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

        foreach ($perms as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin    = Role::firstOrCreate(['name' => 'admin',    'guard_name' => $guard]);
        $gestor   = Role::firstOrCreate(['name' => 'gestor',   'guard_name' => $guard]);
        $corretor = Role::firstOrCreate(['name' => 'corretor', 'guard_name' => $guard]);

        foreach ($perms as $name) {
            if (!$admin->hasPermissionTo($name, $guard)) {
                $admin->givePermissionTo($name);
            }
            if (!$gestor->hasPermissionTo($name, $guard)) {
                $gestor->givePermissionTo($name);
            }
        }

        if (!$corretor->hasPermissionTo('media.view', $guard)) {
            $corretor->givePermissionTo('media.view');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {

    }
};
