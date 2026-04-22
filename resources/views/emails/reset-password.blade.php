@extends('emails.layout', [
    'title'   => 'Recuperação de senha',
    'preview' => 'Recebemos uma solicitação para redefinir sua senha no Alpha Domus CRM.',
])

@section('content')

    <h1 style="margin:0 0 16px 0;font-size:22px;font-weight:600;color:#ffffff;line-height:1.3;">
        Recuperação de senha
    </h1>

    <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#cfcfcf;">
        Olá, {{ $userName }}!
    </p>

    <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#cfcfcf;">
        Recebemos uma solicitação para redefinir a senha da sua conta no
        <strong style="color:#ffffff;">Alpha Domus CRM</strong>. Clique no
        botão abaixo pra criar uma senha nova:
    </p>

    {{-- BOTÃO --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="btn" style="margin:8px 0 24px 0;">
        <tr>
            <td align="center">
                <a href="{{ $resetUrl }}"
                   style="display:inline-block;background:#2d6cdf;color:#ffffff;font-size:15px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">
                    Redefinir minha senha
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px 0;font-size:13px;line-height:1.6;color:#8a8a8a;">
        Este link expira em <strong style="color:#cfcfcf;">{{ $expiresInMinutes }} minutos</strong>
        por questões de segurança.
    </p>

    <p style="margin:0 0 24px 0;font-size:12px;line-height:1.6;color:#6b6b6b;">
        Se o botão acima não funcionar, copie e cole este endereço no navegador:<br>
        <a href="{{ $resetUrl }}" style="color:#2d6cdf;word-break:break-all;">{{ $resetUrl }}</a>
    </p>

    {{-- AVISO DE SEGURANÇA --}}
    <div style="margin-top:24px;padding:14px 16px;background:#0f0f0f;border-left:3px solid #2d6cdf;border-radius:4px;">
        <p style="margin:0;font-size:13px;line-height:1.5;color:#b0b0b0;">
            <strong style="color:#ffffff;">Não foi você que solicitou?</strong><br>
            Ignore este email. Sua senha atual continua válida e nenhuma
            alteração será feita enquanto o link não for usado.
        </p>
    </div>

@endsection
