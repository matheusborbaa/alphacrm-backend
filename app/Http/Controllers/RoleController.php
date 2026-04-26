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

/**
 * Sprint Cargos — controller de Cargos (Roles + Permissions).
 * ---------------------------------------------------------------
 * CRUD admin-only sobre Spatie roles. Cargos `is_system` (admin/
 * gestor/corretor) têm regras especiais:
 *
 *   - Nome e type imutáveis (defesa pra não quebrar middlewares
 *     `role:admin` etc espalhados pelo backend).
 *   - is_system=1 não pode virar 0.
 *   - DELETE bloqueado.
 *   - PUT continua aceitando ajuste de permissions e description
 *     (admin pode "tunar" o que cada cargo system pode fazer).
 *
 * Cargos custom (is_system=0):
 *   - Tudo editável: nome, type (entre admin/gestor/corretor),
 *     description, permissions.
 *   - DELETE só se nenhum usuário tem a role atribuída (pra não
 *     deixar usuário sem cargo).
 *
 * Anti lock-out: admin LOGADO não pode aplicar nenhuma operação que
 * remova `settings.roles` da própria role efetiva. Sem isso ele se
 * trancaria fora da tela e ninguém mais conseguiria reabilitar.
 *
 * Endpoint extra: GET /admin/permissions/catalog devolve a estrutura
 * agrupada pra a UI montar a matriz de checkboxes — single source of
 * truth com o `App\Permissions\Catalog`.
 */
class RoleController extends Controller
{
    /**
     * GET /admin/permissions/catalog
     * Devolve o catálogo agrupado pra UI montar a matriz.
     */
    public function catalog()
    {
        $this->ensureAdmin();

        return response()->json([
            'groups' => Catalog::groups(),
        ]);
    }

    /**
     * GET /admin/roles
     * Lista cargos com permissions atribuídas e contagem de users.
     */
    public function index()
    {
        $this->ensureAdmin();

        $roles = Role::with('permissions:id,name')->orderBy('id')->get();

        // Contagem de usuários por role (whatsapp pivot model_has_roles).
        // Spatie não tem withCount nativo da relação; query manual barata.
        $userCounts = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json($roles->map(function ($r) use ($userCounts) {
            return [
                'id'           => $r->id,
                'name'         => $r->name,
                'type'         => $r->type,        // pode ser null em cargos custom mal-formados
                'is_system'    => (bool) $r->is_system,
                'description'  => $r->description,
                'permissions'  => $r->permissions->pluck('name')->values(),
                'users_count'  => (int) ($userCounts[$r->id] ?? 0),
                'created_at'   => $r->created_at,
            ];
        }));
    }

    /**
     * GET /admin/roles/{role}
     * Detalhe de um cargo específico (mesmo shape do index).
     */
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
            'users_count'  => DB::table('model_has_roles')
                ->where('model_type', \App\Models\User::class)
                ->where('role_id', $role->id)
                ->count(),
        ]);
    }

    /**
     * POST /admin/roles
     * Cria cargo novo. Começa SEM permissions (decisão UX: admin marca
     * tudo manual). Aceita opcionalmente um array de permissions iniciais
     * pra futura UI "Duplicar cargo X" que seria mais ergonômico.
     */
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

        // Cargo type=admin só pode ser criado por quem tem users.assign_admin.
        // Defesa adicional: evita gestor (caso ganhe settings.roles algum dia)
        // criar role admin pra escalar privilégio.
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

    /**
     * PUT /admin/roles/{role}
     * Atualiza cargo. Regras especiais pra is_system (ver doc da classe).
     */
    public function update(Request $request, Role $role)
    {
        $this->ensureAdmin();

        $isSystem = (bool) $role->is_system;

        // Sistema: só description e permissions são editáveis.
        // Custom: tudo editável (com unique check no name).
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

        // Defesa: se é cargo system e tentaram mandar name/type, ignora
        // silenciosamente (não dispara erro pra UI poder reaproveitar
        // mesmo formulário). O frontend deve esconder os inputs de qualquer
        // jeito, mas não custa.
        if ($isSystem) {
            unset($data['name'], $data['type']);
        }

        // Promoção de role pra type=admin segue exigindo users.assign_admin
        if (!$isSystem
            && isset($data['type'])
            && $data['type'] === 'admin'
            && !$request->user()->can('users.assign_admin')) {
            throw ValidationException::withMessages([
                'type' => 'Sem permissão para promover cargo a admin.',
            ]);
        }

        // Anti lock-out: bloqueia se a operação remove settings.roles do
        // CARGO QUE O ADMIN LOGADO TEM. Se passar essa, o admin perderia
        // acesso à tela e ninguém poderia desfazer.
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

    /**
     * DELETE /admin/roles/{role}
     * Só permite se !is_system E nenhum user tem a role atribuída.
     */
    public function destroy(Role $role)
    {
        $this->ensureAdmin();

        if ($role->is_system) {
            return response()->json([
                'message' => 'Cargo do sistema não pode ser excluído.',
            ], 422);
        }

        $usersWithRole = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->where('role_id', $role->id)
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

    // =================================================================
    // Helpers privados
    // =================================================================

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

    /**
     * Bloqueia operação se ela tirar `settings.roles` do cargo que o admin
     * logado USA. Sem isso, o admin sobe a alteração, perde acesso à tela
     * de cargos, e fica sem como reverter.
     *
     * Lógica:
     *   - Se a role afetada NÃO é uma das roles do user logado → segue.
     *   - Se a role afetada É do user logado E o array `permissions` novo
     *     NÃO contém 'settings.roles' → 422.
     *   - Tem outras roles do user que ainda têm settings.roles? Aí libera
     *     (ele ainda terá acesso pelas outras).
     */
    private function guardSelfLockout(Request $request, Role $role, array $newPermissions): void
    {
        $me = $request->user();
        $myRoleIds = $me->roles()->pluck('roles.id')->all();

        if (!in_array($role->id, $myRoleIds, true)) {
            return; // não é minha role, sem risco
        }

        if (in_array('settings.roles', $newPermissions, true)) {
            return; // a alteração mantém a permission, OK
        }

        // Verifica se alguma OUTRA role minha ainda tem settings.roles
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
