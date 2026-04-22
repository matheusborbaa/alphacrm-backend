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

/**
 * CRUD de usuários (corretores, gestores, admins).
 *
 * Os endpoints de listagem e mutação ficam atrás da UserPolicy
 * (users.manage / users.assign_admin). A `update()` legada (self-service
 * de perfil) foi renomeada pra `updateProfile()` pra não confundir.
 */
class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * GET /users/admin — lista de usuários com role e contagem de leads.
     * Aceita search=, role=, active=0|1.
     */
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

        $users = $query->orderBy('name')->get();

        // injeta role_name + lead count na saída (evita N+1 explícito)
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
        ]);
    }

    /**
     * POST /users — cria um novo usuário.
     *
     * Se senha não for informada, gera uma temporária aleatória
     * (o invite opcional usa password-reset link do Laravel).
     */
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
        ]);

        if ($data['role'] === 'admin' && !$request->user()->can('users.assign_admin')) {
            throw ValidationException::withMessages([
                'role' => 'Você não tem permissão para criar usuários admin.',
            ]);
        }

        // Se o admin não informou senha, gera uma provisória de 12 chars.
        // A flag $passwordWasGenerated controla se deve disparar o
        // WelcomeUserMail (só enviamos quando a senha é provisória; se o
        // admin digitou, assume que vai repassar por fora).
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

        // Email de boas-vindas com senha provisória. Só dispara se a senha
        // foi gerada pelo sistema (admin que escolheu senha manualmente
        // provavelmente tem um canal próprio pra repassar). Failure é
        // logado sem bloquear o cadastro — um admin que perder o email
        // ainda consegue ver a senha na resposta HTTP abaixo.
        if ($passwordWasGenerated) {
            try {
                Mail::to($user->email)->send(new WelcomeUserMail($user, $plainPassword));
            } catch (\Throwable $e) {
                \Log::error('Falha ao enviar WelcomeUserMail', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $data['role'],
            ],
            // Devolve a senha temporária pro admin repassar (ou usar invite).
            'temporary_password' => $passwordWasGenerated ? $plainPassword : null,
        ], 201);
    }

    /**
     * PUT /users/{user} — atualiza dados básicos do usuário (admin).
     *
     * Role e status ativo passam por validação extra da policy.
     */
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
        ]);

        // Se mudando a role, valida autorização adicional
        if (!empty($data['role']) && $data['role'] !== ($user->getRoleNames()->first() ?? $user->role)) {
            if (!$request->user()->can('users.manage')) {
                throw ValidationException::withMessages(['role' => 'Sem permissão.']);
            }
            if ($data['role'] === 'admin' && !$request->user()->can('users.assign_admin')) {
                throw ValidationException::withMessages(['role' => 'Só admin pode promover outro admin.']);
            }

            $user->syncRoles([$data['role']]);
            $user->role = $data['role'];
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // role já foi tratado via syncRoles; remove do fill pra não duplicar
        unset($data['role']);

        $user->fill(array_filter($data, fn($v) => !is_null($v)));
        $user->save();

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

    /**
     * DELETE /users/{user} — desativa o usuário (soft-deactivate).
     * Não deleta fisicamente pra não quebrar foreign keys de leads/comissões.
     */
    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        $user->active = false;
        $user->save();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /users/{user}/reactivate — reativa um usuário desativado.
     */
    public function reactivate(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $user->active = true;
        $user->save();

        return response()->json(['success' => true]);
    }

    /**
     * POST /users/{user}/send-invite
     *
     * Reenvio do convite / link de redefinição de senha. Mesmo fluxo do
     * "Esqueci minha senha": gera token no `password_reset_tokens` via
     * broker e dispara o ResetPasswordMail (que aponta pro frontend em
     * /reset-password.html?token=...&email=...).
     *
     * Por que NÃO usar `Password::sendResetLink()`?
     *   - Ela dispara a notificação default do Laravel, que tenta resolver
     *     `route('password.reset', ...)` pra montar o URL. Essa rota só
     *     existe se o projeto usa o Auth UI do Laravel — não é o nosso
     *     caso (reset vive no frontend estático). Resultado: 500 no send.
     *
     * Se o envio falhar (SMTP off, credenciais erradas), devolve 500 com
     * mensagem clara em vez de deixar propagar um stack trace pro front.
     */
    public function sendInvite(Request $request, User $user)
    {
        $this->authorize('update', $user);

        // Gera um token fresco (substitui qualquer um que esteja ativo pra esse
        // email) na mesma tabela usada pelo fluxo /auth/forgot-password.
        $token = Password::broker()->createToken($user);

        try {
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token));
        } catch (\Throwable $e) {
            \Log::error('Falha ao enviar convite/link de redefinição', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

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

    /**
     * POST /me — self-service: usuário logado edita próprio perfil.
     * (Mantido o comportamento original da rota /me.)
     */
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

    /**
     * POST /users/me/status — o próprio corretor muda o status atual.
     *
     * Valores aceitos: 'disponivel' | 'ocupado' | 'offline'.
     *
     * Cooldown (defense-in-depth — frontend já desabilita o select):
     *   - Se o corretor tem `cooldown_until` no futuro, bloqueamos mudança
     *     pra 'disponivel' ou 'ocupado'. Só permitimos 'offline' (se ele
     *     quiser sair antes de terminar o turno, ok — zeramos o cooldown).
     *
     * Efeitos colaterais:
     *   - Se virou 'disponivel', tentamos auto-atribuir o lead órfão mais
     *     antigo pra esse corretor via LeadAssignmentService::tryClaimNextOrphan().
     *     Isso evita que leads fiquem parados na fila só porque ninguém
     *     abriu o dashboard pra ver a fila.
     */
    public function updateStatus(Request $request, LeadAssignmentService $assigner)
    {
        $user = $request->user();

        $data = $request->validate([
            'status' => ['required', Rule::in(['disponivel', 'ocupado', 'offline'])],
        ]);

        $before = strtolower((string) ($user->status_corretor ?? ''));
        $after  = $data['status'];

        // ---- COOLDOWN GUARD -----------------------------------------
        // Se tem cooldown_until no futuro, só aceita ir pra 'offline'.
        $inCooldown = $user->cooldown_until && $user->cooldown_until->isFuture();
        if ($inCooldown && $after !== 'offline') {
            return response()->json([
                'message'        => 'Você está em cooldown após receber um lead. Aguarde o fim do período pra mudar o status.',
                'cooldown_until' => $user->cooldown_until->toIso8601String(),
                'current_status' => $before,
            ], 422);
        }

        $payload = ['status_corretor' => $after];

        // Indo pra 'offline' durante cooldown: zera o cooldown pra não
        // deixar lixo no banco (quando ele voltar, vai vir limpo).
        if ($inCooldown && $after === 'offline') {
            $payload['cooldown_until'] = null;
        }

        $user->update($payload);

        $claimed = null;
        if ($after === 'disponivel' && $before !== 'disponivel') {
            // Corretor acabou de ficar disponível — tenta pegar órfão.
            $claimed = $assigner->tryClaimNextOrphan($user->fresh());
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
}
