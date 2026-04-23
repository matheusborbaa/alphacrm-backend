<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        Layout compartilhado de emails transacionais do Alpha Domus CRM.
        Table-based pra compat com clientes restritos (Outlook, Gmail antigo,
        app nativo do iOS). Cores batem com /login.html: fundo #0e0e0e,
        caixa #161616, accent #2d6cdf.

        Slots esperados:
          - $title    → string, vai no <title> e no header.
          - $preview  → string opcional, preview text invisível (aparece no
                        snippet do inbox, sobrepõe o início do body).
          - @yield('content') → corpo do email.
    --}}
    <title>{{ $title ?? 'Alpha Domus CRM' }}</title>
    <style>
        /* Reset básico pra evitar espaços aleatórios do Gmail/Outlook. */
        body,table,td,p,a,li{ -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        body{ margin:0; padding:0; width:100% !important; background:#0e0e0e; }
        table{ border-collapse:collapse; mso-table-lspace:0; mso-table-rspace:0; }
        img{ border:0; display:block; outline:none; text-decoration:none; }
        a{ color:#2d6cdf; text-decoration:none; }

        /* Utilitário: stack em mobile. */
        @media only screen and (max-width:600px){
            .container{ width:100% !important; padding:16px !important; }
            .card{ padding:24px 20px !important; }
            .btn a{ padding:14px 20px !important; font-size:15px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#0e0e0e;font-family:'Segoe UI',Arial,sans-serif;color:#e7e7e7;">

    {{-- Preview text (invisible on render, visible on inbox snippet). --}}
    @if(!empty($preview))
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#0e0e0e;">
            {{ $preview }}
        </div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0e0e0e;padding:32px 0;">
        <tr>
            <td align="center">

                <table role="presentation" width="560" cellpadding="0" cellspacing="0" class="container" style="width:560px;max-width:560px;">

                    {{-- HEADER --}}
                    {{--
                        Logo é servido pelo mesmo domínio do frontend
                        (FRONTEND_URL no .env). Tem que ser URL absoluta —
                        clientes de email NUNCA carregam imagem relativa.
                        O <a> em volta manda o clique no logo pro CRM.
                        Fallback em texto (alt) continua legível mesmo se
                        o cliente bloquear imagens (comportamento default
                        do Gmail e Outlook pra remetentes novos).
                    --}}
                    @php
                        $brandBase = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
                        $logoUrl   = $brandBase . '/img/logo-alpha.png';
                    @endphp
                    <tr>
                        <td align="center" style="padding:0 0 20px 0;">
                            <a href="{{ $brandBase }}" style="text-decoration:none;">
                                <img src="{{ $logoUrl }}"
                                     alt="Alpha Domus CRM"
                                     width="180"
                                     style="display:block;border:0;outline:none;max-width:180px;height:auto;margin:0 auto;">
                            </a>
                        </td>
                    </tr>

                    {{-- CARD --}}
                    <tr>
                        <td class="card" style="background:#161616;border:1px solid #2a2a2a;border-radius:12px;padding:36px 32px;color:#e7e7e7;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- FOOTER --}}
                    <tr>
                        <td align="center" style="padding:24px 8px 0 8px;color:#6b6b6b;font-size:12px;line-height:1.5;">
                            Este email foi enviado automaticamente pelo Alpha Domus CRM.<br>
                            Se você não reconhece esta mensagem, ignore-a.
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
