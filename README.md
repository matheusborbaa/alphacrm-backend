# AlphaCRM — Backend (Laravel)

Esta é a parte **backend** do AlphaCRM. Toda a documentação, instalação e visão geral do projeto está no README da raiz: [`../README.md`](../README.md).

## Quick reference

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan permissions:sync-admin
php artisan serve
```

## Comandos artisan customizados

| Comando | Quando rodar |
|---------|--------------|
| `permissions:sync-admin` | Sempre que adicionar permissão no `Catalog.php` |
| `leads:release-cooldowns` | Já agendado (a cada minuto) |
| `corretores:mark-offline-inactive` | Já agendado (a cada 5 min) |
| `visits:send-reminders` | Já agendado (a cada 5 min) |
| `google:sync-incoming` | Já agendado (a cada 5 min, se Google configurado) |
| `google:test-push --last` | Diagnóstico — push da última appointment pro Google |
| `images:apply-watermark` | One-shot — aplica marca d'água nas imagens existentes |
| `docs:purge-expired` | Já agendado (diário) |
| `servidor:check-capacity` | Já agendado (horário) |

## Schedule

Definido em `routes/console.php` (Laravel 11+ não usa mais `app/Console/Kernel.php`). Ative o cron do Laravel pra os schedules rodarem:

```cron
* * * * * cd /caminho/pra/alphacrm && php artisan schedule:run >> /dev/null 2>&1
```

## Estrutura

Veja [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md).
