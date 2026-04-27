<?php

namespace App\Console\Commands;

use App\Permissions\Catalog;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza o role `admin` com TODAS as permissões do Catalog,
 * sem mexer nos roles `gestor` e `corretor` (preservando customizações).
 *
 * Útil quando uma nova permissão é adicionada ao Catalog e o admin
 * deveria recebê-la automaticamente.
 *
 * Uso:
 *   php artisan permissions:sync-admin
 */
class SyncAdminPermissions extends Command
{
    protected $signature   = 'permissions:sync-admin';
    protected $description = 'Garante que o role admin tem TODAS as permissões do Catalog (preserva gestor/corretor)';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();


        $existingRoles = Role::all();
        $this->line('Roles existentes no banco:');
        foreach ($existingRoles as $r) {
            $this->line(sprintf('  - "%s" (guard: %s, id: %d)', $r->name, $r->guard_name, $r->id));
        }
        $this->newLine();

        $admin = Role::where('name', 'admin')->first();

        if (!$admin) {
            $this->warn('Role "admin" não encontrado. Criando agora com guard "web"...');
            $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
            $this->line('  ✓ Role criado.');
        }

        $guard = $admin->guard_name ?: 'web';
        $this->info('Usando guard "' . $guard . '" do role admin.');

        $names = array_unique(array_merge(
            Catalog::allNames(),
            Catalog::legacyAll()
        ));

        $this->info('Sincronizando ' . count($names) . ' permissões com o role admin...');

        $created = 0;
        foreach ($names as $name) {
            $perm = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], []);
            if ($perm->wasRecentlyCreated) $created++;
        }
        $this->line("  ✓ {$created} permissão(ões) criada(s) no banco.");


        $admin->load('permissions');
        $missing = array_diff($names, $admin->permissions->pluck('name')->toArray());

        if (empty($missing)) {
            $this->line('  ✓ Admin já tinha todas as permissões. Nada a fazer.');
        } else {
            $this->line('  + Adicionando ao admin: ' . implode(', ', array_slice($missing, 0, 10))
                . (count($missing) > 10 ? ' ...' : ''));
            $admin->givePermissionTo($missing);
            $this->info('  ✓ ' . count($missing) . ' permissão(ões) adicionada(s) ao admin.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();


        $usersWithRole = \App\Models\User::role('admin')->count();
        $usersByColumn = \App\Models\User::where('role', 'admin')->count();
        $this->newLine();
        $this->line("Usuários com role admin (Spatie): {$usersWithRole}");
        $this->line("Usuários com coluna role='admin': {$usersByColumn}");

        if ($usersByColumn > 0 && $usersWithRole < $usersByColumn) {
            $this->warn("Há usuários com coluna role='admin' mas sem o role Spatie atribuído.");
            $this->warn("Rode: php artisan db:seed --class=Database\\\\Seeders\\\\RolesPermissionsSeeder");
            $this->warn("Esse seed também sincroniza usuários com seus roles correspondentes.");
        }

        $this->newLine();
        $this->info('Pronto. Os usuários admin precisam fazer logout/login pra atualizar o token.');
        return self::SUCCESS;
    }
}
