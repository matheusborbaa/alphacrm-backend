<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Contracts\Encryption\DecryptException;

class EmailSettingsController extends Controller
{

    private const KEYS = [
        'mail_driver'       => ['type' => 'enum', 'options' => ['smtp', 'log'], 'default' => 'smtp'],
        'mail_host'         => ['type' => 'string', 'default' => ''],
        'mail_port'         => ['type' => 'int', 'default' => 587, 'min' => 1, 'max' => 65535],
        'mail_username'     => ['type' => 'string', 'default' => ''],

        'mail_password'     => ['type' => 'password', 'default' => ''],
        'mail_encryption'   => ['type' => 'enum', 'options' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
        'mail_from_address' => ['type' => 'email', 'default' => ''],
        'mail_from_name'    => ['type' => 'string', 'default' => ''],
    ];

    public function index()
    {
        $this->ensureAdmin();

        $out = [];
        foreach (self::KEYS as $key => $meta) {
            if ($meta['type'] === 'password') {
                continue;
            }
            $out[$key] = Setting::get($key, $meta['default']);
        }

        $encryptedPass = Setting::get('mail_password', null);
        $out['has_password'] = !empty($encryptedPass);

        return response()->json($out);
    }

    public function update(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'mail_driver'       => ['nullable', 'in:smtp,log'],
            'mail_host'         => ['nullable', 'string', 'max:255'],
            'mail_port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username'     => ['nullable', 'string', 'max:255'],

            'mail_password'     => ['nullable', 'string', 'max:512'],
            'mail_encryption'   => ['nullable', 'in:tls,ssl,none'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name'    => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data as $key => $val) {
            if ($key === 'mail_password') {

                if (is_string($val) && $val !== '') {
                    Setting::set($key, Crypt::encryptString($val), 'Senha SMTP (criptografada)');
                }
                continue;
            }

            if (is_string($val) && $val === '') {
                $val = null;
            }

            Setting::set($key, $val);
        }

        return response()->json(['success' => true]);
    }

    public function test(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $this->applyRuntimeConfig();

        $subject = 'Teste de configuração SMTP — Alpha Domus CRM';
        $body    = "Este é um email de teste enviado pelo Alpha Domus CRM em " . now()->format('d/m/Y H:i:s') . ".\n\n" .
                   "Se você recebeu essa mensagem, a configuração SMTP está funcionando corretamente.";

        $logBase = [
            'to_email'             => $data['to'],
            'to_name'              => null,
            'from_email'           => config('mail.from.address'),
            'from_name'            => config('mail.from.name'),
            'subject'              => $subject,
            'mail_class'           => null,
            'type'                 => \App\Models\EmailLog::TYPE_TEST,
            'triggered_by_user_id' => $request->user()?->id,
            'related_user_id'      => null,
        ];

        try {
            Mail::raw($body, function ($m) use ($data, $subject) {
                $m->to($data['to'])->subject($subject);
            });

            \App\Models\EmailLog::create($logBase + [
                'status'        => \App\Models\EmailLog::STATUS_SENT,
                'error_message' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Email enviado pra {$data['to']}. Verifique a caixa de entrada (e spam).",
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Falha no teste de envio de email', [
                'to'    => $data['to'],
                'error' => $e->getMessage(),
            ]);

            try {
                \App\Models\EmailLog::create($logBase + [
                    'status'        => \App\Models\EmailLog::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            } catch (\Throwable $ignored) {}

            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar: ' . $e->getMessage(),
            ], 422);
        }
    }

    private function applyRuntimeConfig(): void
    {
        $driver = Setting::get('mail_driver', null);
        if (!empty($driver)) {
            Config::set('mail.default', $driver);
        }

        $mailer = Config::get('mail.default', 'smtp');

        $host = Setting::get('mail_host', null);
        if (!empty($host)) {
            Config::set("mail.mailers.{$mailer}.host", $host);
        }

        $port = Setting::get('mail_port', null);
        if (!empty($port)) {
            Config::set("mail.mailers.{$mailer}.port", (int) $port);
        }

        $username = Setting::get('mail_username', null);
        if (!empty($username)) {
            Config::set("mail.mailers.{$mailer}.username", $username);
        }

        $encryption = Setting::get('mail_encryption', null);
        if (!empty($encryption)) {

            Config::set("mail.mailers.{$mailer}.encryption", $encryption === 'none' ? null : $encryption);
        }

        $encryptedPass = Setting::get('mail_password', null);
        if (!empty($encryptedPass)) {
            try {
                $plain = Crypt::decryptString($encryptedPass);
                Config::set("mail.mailers.{$mailer}.password", $plain);
            } catch (DecryptException $e) {

                \Log::warning('mail_password não pôde ser decriptado, caindo no .env', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fromAddress = Setting::get('mail_from_address', null);
        if (!empty($fromAddress)) {
            Config::set('mail.from.address', $fromAddress);
        }

        $fromName = Setting::get('mail_from_name', null);
        if (!empty($fromName)) {
            Config::set('mail.from.name', $fromName);
        }
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
}
