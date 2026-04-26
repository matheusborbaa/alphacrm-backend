<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

class MailSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable $e) {

            return;
        }

        try {
            $this->applyFromSettings();
        } catch (\Throwable $e) {

            \Log::warning('MailSettingsServiceProvider falhou ao aplicar settings', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function applyFromSettings(): void
    {

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
                continue;
            }

            if ($settingKey === 'mail_port') {
                $val = (int) $val;
            }
            Config::set($configPath, $val);
        }

        $encryption = Setting::get('mail_encryption', null);
        if (!empty($encryption)) {
            Config::set(
                "mail.mailers.{$mailer}.encryption",
                $encryption === 'none' ? null : $encryption
            );
        }

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
