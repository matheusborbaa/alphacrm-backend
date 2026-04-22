<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email com link pra reset de senha. Disparado por AuthController@forgotPassword.
 * O link aponta pro frontend /reset-password.html?token=...&email=..., que
 * coleta a nova senha e chama POST /auth/reset-password.
 *
 * O tempo de expiração do token vem de config/auth.php → passwords.users.expire
 * (default do Laravel: 60 minutos).
 */
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

        // Querystring com email e token — o frontend lê via URLSearchParams
        // e manda no POST /auth/reset-password. urlencode() previne contra
        // caracteres especiais no email quebrarem o parsing.
        $resetUrl = $base . '/reset-password.html'
            . '?token=' . urlencode($this->token)
            . '&email=' . urlencode($this->user->email);

        // Minutos de validade vêm da config padrão do Laravel (passwords.users.expire).
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
