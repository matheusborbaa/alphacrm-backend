<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\Rules\Password as PasswordRule;
use App\Models\User;
use App\Models\RefreshToken;
use App\Mail\ResetPasswordMail;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        // Não diferenciamos entre "usuário inexistente", "senha errada" e
        // "usuário desativado" na resposta: tudo retorna o mesmo 401 genérico.
        // Isso evita que um atacante use o login pra enumerar emails
        // cadastrados ou pra descobrir que uma conta existe mas foi bloqueada.
        // O admin que desativou o usuário sabe que ele não consegue mais
        // entrar — não precisa da informação no erro.
        if (!$user || !Hash::check($request->password, $user->password) || !$user->active) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        // Remove tokens antigos
        $user->tokens()->delete();
        RefreshToken::where('user_id', $user->id)->delete();

        // Access Token (15 minutos conceitual)
        $accessToken = $user->createToken('crm-token')->plainTextToken;
        $id = $user->id;
        $name = $user->name;
        $email = $user->email;
        $role = $user->role;
        // Refresh Token (7 dias)
        $refreshToken = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(7)
        ]);

        return response()->json([
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_in' => 900,
    'teste' => 'Matheus2',

    'user' => [
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'role' => $role ?? null
        ]
    ]);
    }


    /**
     * Retorna a role e a lista de permissions do usuário autenticado.
     * O frontend consome isso após o login pra montar o `can()` do
     * core/permissions.js e esconder/mostrar botões e itens de menu.
     */
    public function permissions(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }

        $role = $user->getRoleNames()->first() ?? $user->role;

        $permissions = $user->getAllPermissions()->pluck('name')->values();

        return response()->json([
            'role'        => $role,
            'permissions' => $permissions,
        ]);
    }

    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required'
        ]);

        $hashed = hash('sha256', $request->refresh_token);

        $refresh = RefreshToken::where('token', $hashed)
            ->where('expires_at', '>', now())
            ->first();

        if (!$refresh) {
            return response()->json([
                'message' => 'Refresh inválido'
            ], 401);
        }

        $user = $refresh->user;

        // Usuário pode ter sido desativado APÓS o login. Bloqueia a renovação
        // do access token e limpa o refresh antigo pra forçar nova autenticação
        // (que vai bater no AuthController@login e ser rejeitada com 401).
        if (!$user || !$user->active) {
            if ($user) {
                $user->tokens()->delete();
                RefreshToken::where('user_id', $user->id)->delete();
            }
            return response()->json(['message' => 'Sessão inválida'], 401);
        }

        // gerar novo access
        $accessToken = $user->createToken('crm-token')->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'expires_in' => 900
        ]);
    }

    /**
     * POST /auth/forgot-password
     *
     * Inicia o fluxo de recuperação: gera um token no broker padrão do
     * Laravel (tabela password_reset_tokens) e dispara o ResetPasswordMail
     * com um link pro frontend.
     *
     * Resposta é SEMPRE genérica ("Se o email existir, enviaremos…") mesmo
     * que o email não exista ou o user esteja inativo — evita enumeração
     * de contas. O envio real só acontece se o usuário existe E está ativo.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && $user->active) {
            // Gera token via broker do Laravel (grava em password_reset_tokens
            // com created_at = now(); expiração é validada no reset().)
            $token = Password::broker()->createToken($user);

            // Envia o email via EmailLoggerService — grava histórico em
            // email_logs (sucesso ou falha) e engole a exception pra manter
            // a resposta genérica. Triggered_by é null aqui: quem pediu
            // forgot-password nem está autenticado.
            \App\Services\EmailLoggerService::send(
                to: $user->email,
                mailable: new ResetPasswordMail($user, $token),
                type: \App\Models\EmailLog::TYPE_RESET,
                relatedUserId: $user->id,
                toName: $user->name,
                triggeredBy: null
            );
        }

        return response()->json([
            'message' => 'Se o email estiver cadastrado, você receberá um link de recuperação em instantes.',
        ]);
    }

    /**
     * POST /auth/reset-password
     *
     * Consome o token gerado no forgotPassword e grava a nova senha.
     * Invalida todos os tokens de API (sanctum) e refresh tokens do
     * usuário — qualquer sessão ativa cai e precisa logar de novo, o que
     * é o comportamento esperado depois de trocar senha.
     *
     * Body: { email, token, password, password_confirmation }
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        // Laravel Password::reset() valida o token + atualiza o user + dispara
        // PasswordReset event (que invalidaria o remember_token via listener
        // default). Aqui já usamos o callback pra também invalidar tokens de
        // API e refresh tokens — o remember_token não é usado no app (auth é
        // stateless via sanctum).
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->save();

                $user->tokens()->delete();
                RefreshToken::where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Senha alterada com sucesso. Faça login com a nova senha.',
            ]);
        }

        // Trad do status pra mensagem amigável em português.
        $message = match ($status) {
            Password::INVALID_USER  => 'Não encontramos uma conta com esse email.',
            Password::INVALID_TOKEN => 'Link inválido ou expirado. Solicite um novo.',
            default                 => 'Não foi possível redefinir a senha. Tente novamente.',
        };

        return response()->json(['message' => $message], 422);
    }

}
