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
 * Email de boas-vindas enviado quando um novo usuário é criado no CRM
 * (UserController@store). Contém os dados de acesso — email e senha
 * provisória — e um botão pra ir direto pro login.
 *
 * Implementa ShouldQueue pra não bloquear a resposta HTTP do cadastro.
 * Se a queue não estiver configurada (QUEUE_CONNECTION=sync), roda síncrono
 * normalmente.
 */
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
        // URL do login vem de config/app.php → APP_URL (ou FRONTEND_URL se
        // configurada). Centraliza aqui pra não quebrar se o domínio mudar.
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
