<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Aplica as settings de email (tabela `settings`) em `config('mail.*')`
 * durante o boot da aplicação.
 *
 * Estratégia:
 *   - Para cada chave `mail_*` salva no banco, sobrescreve o config
 *     correspondente. Se a setting estiver vazia/null, mantém o valor
 *     do config/mail.php (que vem do .env) — esse é o fallback.
 *   - `mail_password` fica criptografado no banco com `Crypt`; aqui
 *     decriptamos antes de injetar em `mail.mailers.{driver}.password`.
 *   - Proteções contra bootstrap prematuro:
 *       * Se a tabela `settings` ainda não existe (migration antes de
 *         rodar), não tenta nada.
 *       * Se o APP_KEY mudou e a senha não decripta, loga e ignora —
 *         melhor falhar silencioso que quebrar o boot.
 *
 * NB: o `EmailSettingsController::test()` duplica a lógica em runtime
 * pra poder testar logo após um PUT sem depender do provider ser
 * refeito (worker/php-fpm precisaria de restart).
 */
class MailSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Em ambientes muito restritos (ex.: `php artisan config:cache`
        // sendo rodado antes de ter banco, containers em startup) a tabela
        // pode não existir ainda. Nessa situação, pulamos sem barulho.
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable $e) {
            // Sem conexão com banco ainda — deixa o Laravel usar só o .env.
            return;
        }

        try {
            $this->applyFromSettings();
        } catch (\Throwable $e) {
            // Nunca bloqueia o boot por causa de email. Só loga.
            \Log::warning('MailSettingsServiceProvider falhou ao aplicar settings', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function applyFromSettings(): void
    {
        // Driver primeiro — as demais settings atuam no mailer ativo.
        $driver = Setting::get('mail_driver', null);
        if (!empty($driver)) {
            Config::set('mail.default', $driver);
        }

        $mailer = Config::get('mail.default', 'smtp');

        $map = [
            'mail_host'       => "mail.mailers.{$mailer}.host",
            'mail_port'       => "mail.mailers.{$mailer}.port",
            'mail_username'   => "mail.mailers.{$mailer}.username",
            'mail_from_address' => 'mail.from.address',
            'mail_from_name'    => 'mail.from.name',
        ];

        foreach ($map as $settingKey => $configPath) {
            $val = Setting::get($settingKey, null);
            if ($val === null || $val === '') {
                continue; // fallback: mantém o valor do .env
            }
            // Port é o único que precisa de cast explícito
            if ($settingKey === 'mail_port') {
                $val = (int) $val;
            }
            Config::set($configPath, $val);
        }

        // Encryption: 'none' na UI vira null no config (sem TLS/SSL)
        $encryption = Setting::get('mail_encryption', null);
        if (!empty($encryption)) {
            Config::set(
                "mail.mailers.{$mailer}.encryption",
                $encryption === 'none' ? null : $encryption
            );
        }

        // Senha — criptografada no banco
        $encrypted = Setting::get('mail_password', null);
        if (!empty($encrypted)) {
            try {
                $plain = Crypt::decryptString($encrypted);
                Config::set("mail.mailers.{$mailer}.password", $plain);
            } catch (DecryptException $e) {
                \Log::warning('mail_password não pôde ser decriptado (APP_KEY mudou?). Usando .env como fallback.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
