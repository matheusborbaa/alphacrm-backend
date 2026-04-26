<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeUserMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $temporaryPassword;

    public function __construct(User $user, string $temporaryPassword)
    {
        $this->user = $user;
        $this->temporaryPassword = $temporaryPassword;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bem-vindo ao Alpha Domus CRM',
        );
    }

    public function content(): Content
    {

        $loginUrl = rtrim(config('app.frontend_url') ?: config('app.url'), '/') . '/login.html';

        return new Content(
            view: 'emails.welcome',
            with: [
                'userName'          => $this->user->name,
                'userEmail'         => $this->user->email,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl'          => $loginUrl,
            ],
        );
    }
}
