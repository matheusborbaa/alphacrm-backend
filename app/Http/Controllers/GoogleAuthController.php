<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * I1 — OAuth flow Google.
 *
 * Fluxo:
 *   1. Frontend chama  GET  /admin/google/auth-url  (auth) → recebe URL pra redirecionar
 *   2. Browser vai pro Google, usuário autoriza
 *   3. Google redireciona pra GET /admin/google/callback?code=...&state=...  (sem auth)
 *   4. Backend troca code por tokens, salva, redireciona pro frontend
 *   5. Frontend chama GET /admin/google/status pra mostrar "conectado"
 *   6. Pra desconectar: POST /admin/google/disconnect
 */
class GoogleAuthController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}


    public function status(Request $request)
    {
        $user = $request->user();
        $cred = $user->googleCredential;

        return response()->json([
            'configured' => $this->google->isConfigured(),
            'installed'  => $this->google->isInstalled(),
            'connected'  => (bool) $cred,
            'email'      => $cred?->email,
            'last_synced_at' => $cred?->last_synced_at,
            'last_sync_error' => $cred?->last_sync_error,
        ]);
    }


    public function authUrl(Request $request)
    {
        if (!$this->google->isConfigured()) {
            return response()->json([
                'message' => 'Integração Google não está configurada no servidor. Pede pro admin configurar GOOGLE_CLIENT_ID/SECRET no .env.',
            ], 422);
        }

        $userId = $request->user()->id;
        $nonce  = Str::random(32);


        Cache::put('google_oauth_nonce:' . $userId, $nonce, now()->addMinutes(15));

        $url = $this->google->buildAuthUrl($userId, $nonce);

        return response()->json(['url' => $url]);
    }


    public function callback(Request $request)
    {

        $frontend = \App\Models\Setting::get('google_frontend_callback')
            ?: config('services.google.frontend_callback', '/perfil.php');

        $code  = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error) {
            return redirect($frontend . '?google=error&reason=' . urlencode($error));
        }

        if (!$code || !$state) {
            return redirect($frontend . '?google=error&reason=missing_params');
        }

        try {
            $decoded = json_decode(base64_decode($state), true);
            $userId  = (int) ($decoded['uid'] ?? 0);
            $nonce   = $decoded['nonce'] ?? '';

            $cachedNonce = Cache::pull('google_oauth_nonce:' . $userId);
            if (!$cachedNonce || !hash_equals($cachedNonce, $nonce)) {
                return redirect($frontend . '?google=error&reason=invalid_state');
            }

            $result = $this->google->handleCallback($code, $state);

            return redirect($frontend . '?google=connected&email=' . urlencode($result['email'] ?? ''));
        } catch (\Throwable $e) {
            \Log::error('[google] callback falhou: ' . $e->getMessage());
            return redirect($frontend . '?google=error&reason=' . urlencode(substr($e->getMessage(), 0, 200)));
        }
    }


    public function disconnect(Request $request)
    {
        $ok = $this->google->disconnect($request->user());
        return response()->json(['success' => $ok]);
    }


    public function syncNow(Request $request)
    {
        $user = $request->user();
        $result = $this->google->pullChangesForUser($user);
        return response()->json($result);
    }
}
