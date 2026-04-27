<?php

namespace App\Console\Commands;

use App\Permissions\Catalog;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Re-sincroniza o role admin com tudo do Catalog. Não toca em gestor/corretor.
// Roda sempre que adicionar permissão nova no código pra evitar "permissão não existe" depois.
class SyncAdminPermissions extends Command
{
    protected $signature   = 'permissions:sync-admin';
    protected $description = 'Garante que o role admin tem TODAS as permissões do Catalog (preserva gestor/corretor)';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();


        $hasTypeColumn = \Illuminate\Support\Facades\Schema::hasColumn('roles', 'type');

        $existingRoles = Role::all();
        $this->line('Roles existentes no banco:');
        foreach ($existingRoles as $r) {
            $type = $hasTypeColumn ? ($r->type ?? '—') : '(coluna type ausente)';
            $this->line(sprintf('  - "%s" (guard: %s, type: %s, id: %d)',
                $r->name, $r->guard_name, $type, $r->id));
        }
        $this->newLine();


        $admin = null;
        if ($hasTypeColumn) {
            $admin = Role::where('type', 'admin')->first();
        }
        if (!$admin) {
            $admin = Role::where('name', 'admin')->first();
        }
        if (!$admin) {
            $admin = Role::whereRaw('LOWER(name) IN (?, ?, ?, ?)',
                ['admin', 'administração', 'administracao', 'administrador'])
                ->first();
        }

        if (!$admin) {
            $this->warn('Nenhum role admin encontrado (nem por type=admin, nem por nome). Criando "admin"...');
            $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
            if ($hasTypeColumn) {
                \Illuminate\Support\Facades\DB::table('roles')->where('id', $admin->id)->update(['type' => 'admin']);
            }
            $this->line('  ✓ Role criado.');
        } else {
            $this->info('Role admin identificado: "' . $admin->name . '" (id: ' . $admin->id . ', type: ' . ($admin->type ?? '—') . ')');
        }

        $guard = $admin->guard_name ?: 'web';
        $this->info('Usando guard "' . $guard . '"');

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


        $usersWithRole = \App\Models\User::role($admin->name)->count();
        $usersByColumn = \App\Models\User::where('role', 'admin')->count();
        $this->newLine();
        $this->line("Usuários com role '{$admin->name}' (Spatie): {$usersWithRole}");
        $this->line("Usuários com coluna role='admin': {$usersByColumn}");


        if ($usersByColumn > $usersWithRole) {
            $this->warn("Há {$usersByColumn} users com coluna role='admin' mas só {$usersWithRole} têm o role Spatie '{$admin->name}'.");
            $this->line('  Sincronizando os faltantes...');
            $synced = 0;
            \App\Models\User::where('role', 'admin')->chunkById(50, function ($users) use ($admin, &$synced) {
                foreach ($users as $u) {
                    if (!$u->hasRole($admin->name)) {
                        $u->assignRole($admin);
                        $synced++;
                    }
                }
            });
            $this->info("  ✓ {$synced} usuário(s) recebeu(ram) o role '{$admin->name}'.");
        }

        $this->newLine();
        $this->info('Pronto. Os usuários admin precisam fazer logout/login pra atualizar o token.');
        return self::SUCCESS;
    }
}
