@php
    $startsAtFmt = $startsAt ? \Carbon\Carbon::parse($startsAt)->locale('pt_BR')->isoFormat('dddd, D [de] MMMM [às] HH:mm') : '(sem data)';
    $isOnline = $modality === 'online';
@endphp
@extends('emails.layout', [
    'title'   => 'Nova visita agendada',
    'preview' => "{$corretorName} agendou uma nova visita: " . ($appointment->title ?? ''),
])

@section('content')

    <h1 style="margin:0 0 16px 0;font-size:22px;font-weight:600;color:#ffffff;line-height:1.3;">
        📅 Nova visita do time
    </h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#cfcfcf;">
        <strong style="color:#fff;">{{ $corretorName }}</strong> acabou de agendar uma nova visita:
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;margin:20px 0;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Visita</div>
                <div style="font-size:17px;color:#ffffff;font-weight:600;">{{ $appointment->title ?? 'Visita' }}</div>
            </td>
        </tr>
        @if($leadName)
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Lead</div>
                <div style="font-size:15px;color:#ffffff;">{{ $leadName }}</div>
            </td>
        </tr>
        @endif
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Quando</div>
                <div style="font-size:15px;color:#ffffff;">{{ $startsAtFmt }}</div>
            </td>
        </tr>
        @if($isOnline)
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Modalidade</div>
                <div style="font-size:15px;color:#ffffff;">💻 Online (Google Meet)</div>
            </td>
        </tr>
        @elseif($location)
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Endereço</div>
                <div style="font-size:15px;color:#ffffff;">📍 {{ $location }}</div>
            </td>
        </tr>
        @endif
    </table>

    <p style="margin:24px 0 0 0;font-size:12px;line-height:1.6;color:#6b6b6b;">
        Você está recebendo essa cópia porque é gestor da equipe de {{ $corretorName }}.
    </p>

@endsection
