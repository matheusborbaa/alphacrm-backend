<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $token;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperação de senha — Alpha Domus CRM',
        );
    }

    public function content(): Content
    {
        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');

        $resetUrl = $base . '/reset-password.html'
            . '?token=' . urlencode($this->token)
            . '&email=' . urlencode($this->user->email);

        $expiresInMinutes = (int) config('auth.passwords.users.expire', 60);

        return new Content(
            view: 'emails.reset-password',
            with: [
                'userName'         => $this->user->name,
                'resetUrl'         => $resetUrl,
                'expiresInMinutes' => $expiresInMinutes,
            ],
        );
    }
}
