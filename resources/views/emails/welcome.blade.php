@extends('emails.layout', [
    'title'   => 'Bem-vindo ao Alpha Domus CRM',
    'preview' => 'Sua conta foi criada. Veja abaixo sua senha provisória para acessar.',
])

@section('content')

    <h1 style="margin:0 0 16px 0;font-size:22px;font-weight:600;color:#ffffff;line-height:1.3;">
        Bem-vindo, {{ $userName }}!
    </h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#cfcfcf;">
        Sua conta no <strong style="color:#ffffff;">Alpha Domus CRM</strong> foi criada.
        Abaixo estão seus dados de acesso:
    </p>

    {{-- BLOCO DE CREDENCIAIS --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;margin:20px 0;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">
                    Email
                </div>
                <div style="font-size:15px;color:#ffffff;font-family:'Courier New',monospace;word-break:break-all;">
                    {{ $userEmail }}
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">
                    Senha provisória
                </div>
                <div style="font-size:17px;color:#ffffff;font-family:'Courier New',monospace;font-weight:700;letter-spacing:2px;">
                    {{ $temporaryPassword }}
                </div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 24px 0;font-size:14px;line-height:1.6;color:#b0b0b0;">
        Por segurança, recomendamos que você
        <strong style="color:#ffffff;">altere sua senha</strong> no primeiro
        acesso, em "Meu perfil".
    </p>

    {{-- BOTÃO --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="btn" style="margin:8px 0 24px 0;">
        <tr>
            <td align="center">
                <a href="{{ $loginUrl }}"
                   style="display:inline-block;background:#2d6cdf;color:#ffffff;font-size:15px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">
                    Acessar o CRM
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0;font-size:12px;line-height:1.6;color:#6b6b6b;">
        Se o botão acima não funcionar, copie e cole este endereço no navegador:<br>
        <a href="{{ $loginUrl }}" style="color:#2d6cdf;word-break:break-all;">{{ $loginUrl }}</a>
    </p>

@endsection
