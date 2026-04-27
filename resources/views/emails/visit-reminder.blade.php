@php
    $when = $kind === '1h' ? 'em 1 hora' : 'amanhã';
    $whenIcon = $kind === '1h' ? '⏰' : '📅';
    $startsAtFmt = $startsAt ? \Carbon\Carbon::parse($startsAt)->locale('pt_BR')->isoFormat('dddd, D [de] MMMM [às] HH:mm') : '';
    $isOnline = $modality === 'online';
@endphp
@extends('emails.layout', [
    'title'   => 'Lembrete de visita',
    'preview' => "Você tem uma visita {$when}: " . ($appointment->title ?? ''),
])

@section('content')

    <h1 style="margin:0 0 16px 0;font-size:22px;font-weight:600;color:#ffffff;line-height:1.3;">
        {{ $whenIcon }} Lembrete de visita {{ $when }}
    </h1>

    <p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#cfcfcf;">
        Olá <strong style="color:#fff;">{{ $recipientName }}</strong>, você tem a seguinte visita agendada:
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
                @if($attendeeEmail || $attendeePhone)
                    <div style="font-size:13px;color:#b0b0b0;margin-top:4px;">
                        @if($attendeeEmail) {{ $attendeeEmail }} @endif
                        @if($attendeeEmail && $attendeePhone) · @endif
                        @if($attendeePhone) {{ $attendeePhone }} @endif
                    </div>
                @endif
            </td>
        </tr>
        @endif
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Quando</div>
                <div style="font-size:15px;color:#ffffff;">{{ $startsAtFmt }}</div>
            </td>
        </tr>
        @if($isOnline && $meetingUrl)
        <tr>
            <td style="padding:0 20px 18px 20px;">
                <div style="font-size:12px;color:#8a8a8a;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Modalidade</div>
                <div style="font-size:15px;color:#ffffff;">💻 Online (Google Meet)</div>
                <div style="margin-top:8px;">
                    <a href="{{ $meetingUrl }}" style="color:#2d6cdf;font-size:14px;word-break:break-all;">{{ $meetingUrl }}</a>
                </div>
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

    @if($isOnline && $meetingUrl)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="btn" style="margin:8px 0 24px 0;">
        <tr>
            <td align="center">
                <a href="{{ $meetingUrl }}"
                   style="display:inline-block;background:#2d6cdf;color:#ffffff;font-size:15px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">
                    Abrir Google Meet
                </a>
            </td>
        </tr>
    </table>
    @endif

    <p style="margin:24px 0 0 0;font-size:12px;line-height:1.6;color:#6b6b6b;">
        Esse é um lembrete automático do AlphaCRM. Se a visita foi cancelada ou remarcada,
        atualize na agenda do CRM.
    </p>

@endsection
