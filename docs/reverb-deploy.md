# Deploy do Reverb (chat em tempo real)

Guia completo pra subir o Laravel Reverb no AlphaCRM em produção.
Feito pro admin de infra (não desenvolvedor).

## 1. Instalar o pacote

Na raiz do projeto backend (`alphacrm/`):

```bash
composer require laravel/reverb
php artisan install:broadcasting
```

O comando `install:broadcasting` faz automaticamente:

- Instala o `laravel/reverb`.
- Cria/atualiza `config/broadcasting.php` com o driver `reverb`.
- Escreve keys no `.env` (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`).
- Adiciona `Route::middleware(['auth:sanctum'])->group(...)` pro `/broadcasting/auth`.

## 2. Conferir o `.env`

Deve ter algo assim (valores gerados):

```
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=123456
REVERB_APP_KEY=xxxxxxxxxxxxxxxxxxxx
REVERB_APP_SECRET=yyyyyyyyyyyyyyyyyyyy
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

# Em produção com SSL na frente (nginx/caddy):
REVERB_HOST=seuchat.alphadomus.com.br
REVERB_PORT=443
REVERB_SCHEME=https
```

> **Importante**: o `REVERB_APP_KEY` é **público** — o frontend carrega
> esse valor via `/api/realtime/config`. Já o `REVERB_APP_SECRET` é
> privado e **não pode vazar pro navegador**.

## 3. Subir o daemon

Em dev:

```bash
php artisan reverb:start
```

Em produção precisa ser daemon permanente. Usando **supervisor**:

```ini
# /etc/supervisor/conf.d/alphacrm-reverb.conf
[program:alphacrm-reverb]
command=/usr/bin/php /var/www/alphacrm/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/alphacrm-reverb.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start alphacrm-reverb
```

## 4. Expor porta WebSocket

O Reverb escuta em `REVERB_PORT` (default 8080). Precisa estar acessível
pelo navegador do cliente. Opções:

### A) Mesma porta 443 via reverse proxy (recomendado)

nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name seuchat.alphadomus.com.br;

    # certificado SSL...

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
    }
}
```

Então no `.env`:

```
REVERB_HOST=seuchat.alphadomus.com.br
REVERB_PORT=443
REVERB_SCHEME=https
```

### B) Porta separada (mais simples, menos elegante)

Abrir `8080` no firewall (`ufw allow 8080/tcp`) e no `.env`:

```
REVERB_HOST=seu-ip-publico
REVERB_PORT=8080
REVERB_SCHEME=http
```

Browsers com site em HTTPS **não conectam** em `ws://` — só `wss://`.
Então essa opção B só serve pra dev. Em produção use (A).

## 5. Testar

### Backend responde config?

```bash
curl -H "Authorization: Bearer <token-do-user>" \
     https://seucrm.alphadomus.com.br/api/realtime/config
```

Deve retornar `{"enabled": true, "key": "...", "host": "...", ...}`.

### Frontend conecta?

1. Abrir duas abas do CRM com usuários diferentes logados.
2. Abrir `/chat.php` em ambas (cada uma numa conversa).
3. DevTools → console → deve aparecer `[realtime] Echo conectado ao Reverb.`
4. Envia msg de uma → aparece instantâneo na outra (antes dos 6s do polling).
5. Marca como lida → ✓✓ aparece instantâneo pra quem mandou.

## 6. Fallback automático

Se qualquer parte do Reverb falhar (daemon caído, porta bloqueada,
.env mal configurado), o frontend cai silenciosamente pro **polling**
que existia antes:

- `/chat/unread-count` a cada 15s
- `pollActiveMessages()` a cada 6s na conversa ativa

O usuário **não percebe nada travado** — só fica com latência de até
6s ao invés de imediato. Portanto, é seguro deployar o Reverb sem
pressa: o chat continua funcionando enquanto você configura.

## 7. Monitoramento

Logs úteis em produção:

```bash
# Daemon
tail -f /var/log/alphacrm-reverb.log

# Laravel (broadcast errors)
tail -f /var/www/alphacrm/storage/logs/laravel.log | grep -i 'broadcast\|reverb'
```

Métricas rápidas via artisan:

```bash
php artisan reverb:start --debug  # verbose
```

## 8. Rollback

Pra desligar temporariamente sem desinstalar:

```bash
# .env
BROADCAST_CONNECTION=log
```

O endpoint `/api/realtime/config` retorna `{"enabled":false}` e o
frontend pula tentando conectar. Polling volta a ser a engine única.
