<?php

namespace App\Http\Controllers;

use App\Permissions\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{

    public function catalog()
    {
        $this->ensureAdmin();

        return response()->json([
            'groups' => Catalog::groups(),
        ]);
    }

    public function index()
    {
        $this->ensureAdmin();

        $roles = Role::with('permissions:id,name')->orderBy('id')->get();

        $userCounts = DB::table('model_has_roles as mhr')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', \App\Models\User::class)
            ->select('mhr.role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('mhr.role_id')
            ->pluck('total', 'mhr.role_id');

        return response()->json($roles->map(function ($r) use ($userCounts) {
            return [
                'id'           => $r->id,
                'name'         => $r->name,
                'type'         => $r->type,
                'is_system'    => (bool) $r->is_system,
                'description'  => $r->description,
                'permissions'  => $r->permissions->pluck('name')->values(),
                'users_count'  => (int) ($userCounts[$r->id] ?? 0),
                'created_at'   => $r->created_at,
            ];
        }));
    }

    public function show(Role $role)
    {
        $this->ensureAdmin();
        $role->load('permissions:id,name');

        return response()->json([
            'id'           => $role->id,
            'name'         => $role->name,
            'type'         => $role->type,
            'is_system'    => (bool) $role->is_system,
            'description'  => $role->description,
            'permissions'  => $role->permissions->pluck('name')->values(),

            'users_count'  => DB::table('model_has_roles as mhr')
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->where('mhr.model_type', \App\Models\User::class)
                ->where('mhr.role_id', $role->id)
                ->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:80', 'unique:roles,name'],
            'type'        => ['required', Rule::in(['admin', 'gestor', 'corretor'])],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Catalog::allNames())],
        ]);

        if ($data['type'] === 'admin'
            && !$request->user()->can('users.assign_admin')) {
            throw ValidationException::withMessages([
                'type' => 'Sem permissão para criar cargo do tipo admin.',
            ]);
        }

        $role = Role::create([
            'name'        => $data['name'],
            'guard_name'  => 'web',
            'type'        => $data['type'],
            'is_system'   => false,
            'description' => $data['description'] ?? null,
        ]);

        if (!empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        $this->forgetCache();

        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'type'        => $role->type,
            'is_system'   => false,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->values(),
            'users_count' => 0,
        ], 201);
    }

    public function update(Request $request, Role $role)
    {
        $this->ensureAdmin();

        $isSystem = (bool) $role->is_system;

        $rules = [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Catalog::allNames())],
        ];

        if (!$isSystem) {
            $rules['name'] = [
                'sometimes',
                'string',
                'max:80',
                Rule::unique('roles', 'name')->ignore($role->id),
            ];
            $rules['type'] = ['sometimes', Rule::in(['admin', 'gestor', 'corretor'])];
        }

        $data = $request->validate($rules);

        if ($isSystem) {
            unset($data['name'], $data['type']);
        }

        if (!$isSystem
            && isset($data['type'])
            && $data['type'] === 'admin'
            && !$request->user()->can('users.assign_admin')) {
            throw ValidationException::withMessages([
                'type' => 'Sem permissão para promover cargo a admin.',
            ]);
        }

        if (array_key_exists('permissions', $data)) {
            $this->guardSelfLockout($request, $role, $data['permissions']);
        }

        DB::transaction(function () use ($role, $data) {
            $patch = array_intersect_key($data, array_flip(['name', 'type', 'description']));
            if (!empty($patch)) {
                $role->fill($patch)->save();
            }
            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions']);
            }
        });

        $this->forgetCache();
        $role->refresh()->load('permissions:id,name');

        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'type'        => $role->type,
            'is_system'   => (bool) $role->is_system,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->values(),
        ]);
    }

    public function destroy(Role $role)
    {
        $this->ensureAdmin();

        if ($role->is_system) {
            return response()->json([
                'message' => 'Cargo do sistema não pode ser excluído.',
            ], 422);
        }

        $usersWithRole = DB::table('model_has_roles as mhr')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', \App\Models\User::class)
            ->where('mhr.role_id', $role->id)
            ->count();

        if ($usersWithRole > 0) {
            return response()->json([
                'message' => "Cargo está atribuído a {$usersWithRole} usuário(s). "
                           . 'Reatribua ou desative os usuários antes de excluir.',
            ], 422);
        }

        $role->delete();
        $this->forgetCache();

        return response()->json(['ok' => true]);
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }

    private function guardSelfLockout(Request $request, Role $role, array $newPermissions): void
    {
        $me = $request->user();
        $myRoleIds = $me->roles()->pluck('roles.id')->all();

        if (!in_array($role->id, $myRoleIds, true)) {
            return;
        }

        if (in_array('settings.roles', $newPermissions, true)) {
            return;
        }

        $stillHave = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereIn('rhp.role_id', array_diff($myRoleIds, [$role->id]))
            ->where('p.name', 'settings.roles')
            ->exists();

        if (!$stillHave) {
            throw ValidationException::withMessages([
                'permissions' => 'Operação bloqueada: você perderia acesso à própria '
                              . 'tela de Cargos. Atribua "settings.roles" a outro cargo '
                              . 'que você também possua antes de remover daqui.',
            ]);
        }
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
