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

        $guard = 'web';

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

        $admin = Role::where('name', 'admin')->where('guard_name', $guard)->first();
        if (!$admin) {
            $this->error('Role "admin" não encontrado. Rode primeiro: php artisan db:seed --class=Database\\Seeders\\RolesPermissionsSeeder');
            return self::FAILURE;
        }


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

        $this->newLine();
        $this->info('Pronto. Os usuários admin precisam fazer logout/login pra atualizar o token.');
        return self::SUCCESS;
    }
}
