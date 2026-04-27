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

/**
 * L8+I1 — Notifica gestor quando alguém do time agenda nova visita.
 */
class NewVisitForGestorMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public User $gestor,
        public User $corretor,
    ) {}

    public function envelope(): Envelope
    {
        $corretorName = $this->corretor->name;
        return new Envelope(
            subject: "Nova visita agendada por {$corretorName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-visit-for-gestor',
            with: [
                'gestorName'    => $this->gestor->name,
                'corretorName'  => $this->corretor->name,
                'appointment'   => $this->appointment,
                'leadName'      => $this->appointment->lead?->name,
                'startsAt'      => $this->appointment->starts_at ?: $this->appointment->due_at,
                'modality'      => $this->appointment->modality ?: 'presencial',
                'location'      => $this->appointment->location,
                'meetingUrl'    => $this->appointment->meeting_url,
            ],
        );
    }
}
