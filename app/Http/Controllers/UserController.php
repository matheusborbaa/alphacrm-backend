<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Lead;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Services\LeadAssignmentService;
use App\Mail\WelcomeUserMail;
use App\Mail\ResetPasswordMail;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'avatar',
                'active',
                'status_corretor',
                'last_lead_assigned_at',
                'created_at',
            ])
            ->with('roles:id,name');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }

        if ($request->filled('role')) {
            $role = $request->get('role');
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        if ($request->filled('active')) {
            $query->where('active', (bool) $request->get('active'));
        }

        $allowedIds = $request->user()->accessibleUserIds();
        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds);
        }

        $users = $query->orderBy('name')->get();

        $leadCounts = Lead::selectRaw('assigned_user_id, COUNT(*) as total')
            ->whereIn('assigned_user_id', $users->pluck('id'))
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        $result = $users->map(function ($u) use ($leadCounts) {
            return [
                'id'                    => $u->id,
                'name'                  => $u->name,
                'email'                 => $u->email,
                'phone'                 => $u->phone,
                'avatar'                => $u->avatar,
                'active'                => (bool) $u->active,
                'status_corretor'       => $u->status_corretor,
                'last_lead_assigned_at' => $u->last_lead_assigned_at,
                'created_at'            => $u->created_at,
                'role'                  => $u->getRoleNames()->first() ?? $u->role,
                'leads_count'           => (int) ($leadCounts[$u->id] ?? 0),
            ];
        });

        return response()->json($result);
    }

    public function checkEmail(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $data = $request->validate([
            'email'   => 'required|email|max:255',
            'exclude' => 'nullable|integer',
        ]);

        $query = User::where('email', $data['email']);
        if (!empty($data['exclude'])) {
            $query->where('id', '!=', $data['exclude']);
        }

        $user = $query->first();

        return response()->json([
            'exists'   => (bool) $user,
            'conflict' => $user ? [
                'id'     => $user->id,
                'name'   => $user->name,
                'role'   => $user->getRoleNames()->first() ?? $user->role,
                'active' => (bool) $user->active,
            ] : null,
        ]);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return response()->json([
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'phone'           => $user->phone,
            'avatar'          => $user->avatar,
            'active'          => (bool) $user->active,
            'status_corretor' => $user->status_corretor,
            'role'            => $user->getRoleNames()->first() ?? $user->role,

            'parent_user_id'              => $user->parent_user_id,
            'empreendimento_access_mode'  => $user->empreendimento_access_mode ?? 'all',
            'empreendimento_ids'          => $user->empreendimentos()->pluck('empreendimentos.id')->all(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'phone'    => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'role'     => ['required', Rule::in(['admin', 'gestor', 'corretor'])],
            'active'   => 'boolean',

            'parent_user_id'              => ['nullable', 'integer', 'exists:users,id'],
            'empreendimento_access_mode'  => ['nullable', Rule::in(['all', 'specific'])],
            'empreendimento_ids'          => ['nullable', 'array'],
            'empreendimento_ids.*'        => ['integer', 'exists:empreendimentos,id'],
        ]);

        if ($data['role'] === 'admin' && !$request->user()->can('users.assign_admin')) {
            throw ValidationException::withMessages([
                'role' => 'Você não tem permissão para criar usuários admin.',
            ]);
        }

        $passwordWasGenerated = empty($data['password']);
        $plainPassword = $passwordWasGenerated ? Str::random(12) : $data['password'];

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'password' => Hash::make($plainPassword),
            'role'     => $data['role'],
            'active'   => $data['active'] ?? true,
        ]);

        $user->assignRole($data['role']);

        try {
            $this->applySeccionamentoFields($user, $data, $request->user());
        } catch (ValidationException $e) {
            $user->delete();
            throw $e;
        }

        if ($passwordWasGenerated) {
            \App\Services\EmailLoggerService::send(
                to: $user->email,
                mailable: new WelcomeUserMail($user, $plainPassword),
                type: \App\Models\EmailLog::TYPE_WELCOME,
                relatedUserId: $user->id,
                toName: $user->name
            );
        }

        return response()->json([
            'success' => true,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $data['role'],
            ],

            'temporary_password' => $passwordWasGenerated ? $plainPassword : null,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name'     => 'nullable|string|max:255',
            'email'    => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'    => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'active'   => 'nullable|boolean',
            'role'     => ['nullable', Rule::in(['admin', 'gestor', 'corretor'])],

            'parent_user_id'              => ['nullable', 'integer', 'exists:users,id'],
            'empreendimento_access_mode'  => ['nullable', Rule::in(['all', 'specific'])],
            'empreendimento_ids'          => ['nullable', 'array'],
            'empreendimento_ids.*'        => ['integer', 'exists:empreendimentos,id'],
        ]);

        if (!empty($data['role']) && $data['role'] !== ($user->getRoleNames()->first() ?? $user->role)) {
            $caller = $request->user();
            $callerRole = strtolower($caller->getRoleNames()->first() ?? $caller->role ?? '');
            $oldRole    = strtolower($user->getRoleNames()->first() ?? $user->role ?? '');
            $newRole    = strtolower($data['role']);

            $canChangeRole = $caller->can('users.manage') || $caller->can('users.update');
            if (!$canChangeRole) {
                throw ValidationException::withMessages(['role' => 'Sem permissão.']);
            }
            if ($newRole === 'admin' && !$caller->can('users.assign_admin')) {
                throw ValidationException::withMessages(['role' => 'Só admin pode promover outro admin.']);
            }


            if ($oldRole === 'admin' && $newRole !== 'admin' && $callerRole !== 'admin') {
                throw ValidationException::withMessages([
                    'role' => 'Só outro admin pode rebaixar este usuário.',
                ]);
            }


            if ($oldRole === 'admin' && $newRole !== 'admin' && $caller->id === $user->id) {

                $remainingAdmins = User::where('id', '!=', $user->id)
                    ->where(function ($q) {
                        $q->where('role', 'admin')
                          ->orWhereHas('roles', fn($r) => $r->where('name', 'admin'));
                    })
                    ->where('active', true)
                    ->count();
                if ($remainingAdmins === 0) {
                    throw ValidationException::withMessages([
                        'role' => 'Você é o único admin ativo. Promova outro usuário a admin antes de se rebaixar.',
                    ]);
                }
            }

            $user->syncRoles([$data['role']]);
            $user->role = $data['role'];
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        unset($data['role']);

        $seccionamentoFields = [
            'parent_user_id'             => $data['parent_user_id']             ?? null,
            'empreendimento_access_mode' => $data['empreendimento_access_mode'] ?? null,
            'empreendimento_ids'         => $data['empreendimento_ids']         ?? null,
        ];
        unset($data['parent_user_id'], $data['empreendimento_access_mode'], $data['empreendimento_ids']);

        $user->fill(array_filter($data, fn($v) => !is_null($v)));
        $user->save();

        $this->applySeccionamentoFields($user, $seccionamentoFields, $request->user());

        return response()->json([
            'success' => true,
            'user'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'active' => (bool) $user->active,
                'role'   => $user->getRoleNames()->first() ?? $user->role,
            ],
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        $user->active = false;
        $user->save();

        return response()->json(['deleted' => true]);
    }

    public function reactivate(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $user->active = true;
        $user->save();

        return response()->json(['success' => true]);
    }

    public function sendInvite(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $token = Password::broker()->createToken($user);

        $ok = \App\Services\EmailLoggerService::send(
            to: $user->email,
            mailable: new ResetPasswordMail($user, $token),
            type: \App\Models\EmailLog::TYPE_INVITE,
            relatedUserId: $user->id,
            toName: $user->name
        );

        if (!$ok) {
            return response()->json([
                'success' => false,
                'message' => 'Não foi possível enviar o email. Verifique a configuração SMTP em Configurações → Email.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Link de redefinição de senha enviado pra {$user->email}.",
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'     => 'nullable|string|max:255',
            'email'    => 'nullable|email|max:255',
            'phone'    => 'nullable|string|max:20',
            'password' => 'nullable|min:6',
            'avatar'   => 'nullable|image|max:2048',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar'] = '/storage/' . $path;
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'user'    => $user,
        ]);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'chat_read_receipts' => ['sometimes', 'boolean'],
            'theme_preference'   => ['sometimes', 'nullable', Rule::in(['system', 'light', 'dark'])],
        ]);

        if (empty($data)) {
            return response()->json([
                'message' => 'Nenhuma preferência foi enviada.',
            ], 422);
        }

        $user = $request->user();
        $user->update($data);

        return response()->json([
            'success' => true,
            'user'    => $user->fresh(),
        ]);
    }

    public function uploadPhoto(Request $request, User $user)
    {
        $request->validate([
            'avatar' => 'required|image|max:2048',
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => '/storage/' . $path]);

        return response()->json([
            'success' => true,
            'user'    => $user->fresh(),
        ]);
    }

    public function updateStatus(Request $request, LeadAssignmentService $assigner)
    {
        $user = $request->user();

        $data = $request->validate([
            'status' => ['required', Rule::in(['disponivel', 'ocupado', 'offline'])],
        ]);

        $before = strtolower((string) ($user->status_corretor ?? ''));
        $after  = $data['status'];

        $inCooldown = $user->cooldown_until && $user->cooldown_until->isFuture();
        if ($inCooldown && $after !== 'offline') {
            return response()->json([
                'message'        => 'Você está em cooldown após receber um lead. Aguarde o fim do período pra mudar o status.',
                'cooldown_until' => $user->cooldown_until->toIso8601String(),
                'current_status' => $before,
            ], 422);
        }

        $payload = ['status_corretor' => $after];

        if ($inCooldown && $after === 'offline') {
            $payload['cooldown_until'] = null;
        }

        $user->update($payload);

        $claimed = null;
        if ($after === 'disponivel' && $before !== 'disponivel') {

            $claimed = $assigner->tryClaimNextOrphan($user->fresh());
        }

        if ($after === 'disponivel') {
            $user->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->json([
            'status'         => $after,
            'cooldown_until' => $user->fresh()->cooldown_until?->toIso8601String(),
            'claimed_lead'   => $claimed ? [
                'id'   => $claimed->id,
                'name' => $claimed->name,
            ] : null,
        ]);
    }

    public function heartbeat(Request $request)
    {
        $user = $request->user();
        if ($user) {

            $user->forceFill(['last_seen_at' => now()])->save();
        }
        return response()->json(['ok' => true, 'at' => now()->toIso8601String()]);
    }

    private function applySeccionamentoFields(User $target, array $fields, User $actor): void
    {
        $isAdmin = strtolower((string) ($actor->role ?? '')) === 'admin';

        if (array_key_exists('parent_user_id', $fields) && $fields['parent_user_id'] !== null) {
            $parentId = (int) $fields['parent_user_id'];

            if ($parentId === $target->id) {
                throw ValidationException::withMessages([
                    'parent_user_id' => 'Usuário não pode ser gestor de si mesmo.',
                ]);
            }

            $parent = User::find($parentId);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_user_id' => 'Gestor selecionado não existe.',
                ]);
            }
            $parentRole = strtolower((string) ($parent->role ?? ''));
            if (!in_array($parentRole, ['admin', 'gestor'], true)) {
                throw ValidationException::withMessages([
                    'parent_user_id' => 'O gestor responsável precisa ter cargo de admin ou gestor.',
                ]);
            }

            $cursor = $parent;
            $depth  = 0;
            while ($cursor && $depth < 10) {
                if ($cursor->parent_user_id === $target->id) {
                    throw ValidationException::withMessages([
                        'parent_user_id' => 'Hierarquia inválida — criaria um ciclo.',
                    ]);
                }
                $cursor = $cursor->parent_user_id ? User::find($cursor->parent_user_id) : null;
                $depth++;
            }

            $target->parent_user_id = $parentId;
        }

        $newMode = $fields['empreendimento_access_mode'] ?? null;
        $ids     = $fields['empreendimento_ids'] ?? null;

        if ($newMode !== null) {
            if ($newMode === 'all') {

                $target->empreendimento_access_mode = 'all';
                $target->save();
                $target->empreendimentos()->sync([]);
                return;
            }

            if (!is_array($ids)) {
                throw ValidationException::withMessages([
                    'empreendimento_ids' => 'Informe a lista de empreendimentos quando o modo é "específico".',
                ]);
            }

            $ids = array_values(array_unique(array_map('intval', $ids)));

            if (!$isAdmin) {
                $actorIds = $actor->accessibleEmpreendimentoIds()->all();
                $forbidden = array_diff($ids, $actorIds);
                if (!empty($forbidden)) {
                    throw ValidationException::withMessages([
                        'empreendimento_ids' => 'Você não pode dar acesso a empreendimentos que você mesmo não tem ('
                            . implode(', ', $forbidden) . ').',
                    ]);
                }
            }

            $target->empreendimento_access_mode = 'specific';
            $target->save();
            $target->empreendimentos()->sync($ids);
        } else {

            if ($target->isDirty('parent_user_id')) {
                $target->save();
            }
        }
    }
}
