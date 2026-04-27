<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Lembrete de visita pro corretor. Disparado pelo command visits:send-reminders.
class VisitReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public User $recipient,
        public string $kind = '24h',
    ) {}

    public function envelope(): Envelope
    {
        $when = $this->kind === '1h' ? 'em 1 hora' : 'amanhã';
        $title = $this->appointment->title ?? 'Visita';

        return new Envelope(
            subject: "Lembrete: {$title} — {$when}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.visit-reminder',
            with: [
                'recipientName'  => $this->recipient->name,
                'appointment'    => $this->appointment,
                'kind'           => $this->kind,
                'leadName'       => $this->appointment->lead?->name,
                'startsAt'       => $this->appointment->starts_at ?: $this->appointment->due_at,
                'modality'       => $this->appointment->modality ?: 'presencial',
                'location'       => $this->appointment->location,
                'meetingUrl'     => $this->appointment->meeting_url,
                'attendeeEmail'  => $this->appointment->attendee_email,
                'attendeePhone'  => $this->appointment->attendee_phone,
            ],
        );
    }
}
