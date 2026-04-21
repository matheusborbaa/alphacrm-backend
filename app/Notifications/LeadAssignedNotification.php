<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notificação disparada quando um lead é atribuído a um corretor.
 *
 *  - `database` → popup sonoro via polling do frontend (/notifications).
 *  - `mail`     → e-mail imediato pro corretor com link direto pro lead.
 *
 * Fica em queue por padrão pra não travar o request HTTP de cadastro/rodízio.
 */
class LeadAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead)
    {
    }

    /**
     * Canais de entrega. Só manda e-mail se o corretor tiver email preenchido.
     */
    public function via($notifiable): array
    {
        $channels = ['database'];

        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Payload salvo na tabela `notifications`. O frontend consome isso
     * via /notifications e dispara o popup + som quando o count sobe.
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type'      => 'lead_assigned',
            'title'     => 'Novo lead atribuído',
            'message'   => 'Você recebeu o lead ' . ($this->lead->name ?? '#' . $this->lead->id),
            'lead_id'   => $this->lead->id,
            'lead_name' => $this->lead->name,
            'phone'     => $this->lead->phone,
            'channel'   => $this->lead->channel,
            'sound'     => 'new-lead',
        ];
    }

    /**
     * E-mail pro corretor. Mantém linguagem objetiva — corretor precisa
     * agir rápido por causa do SLA de primeiro contato.
     */
    public function toMail($notifiable): MailMessage
    {
        $appUrl = rtrim(config('app.frontend_url') ?? config('app.url') ?? '', '/');
        $leadUrl = $appUrl . '/lead.html?id=' . $this->lead->id;

        $mail = (new MailMessage)
            ->subject('🔔 Novo lead atribuído: ' . ($this->lead->name ?? '#' . $this->lead->id))
            ->greeting('Olá, ' . ($notifiable->name ?? 'corretor') . '!')
            ->line('Um novo lead foi atribuído a você.')
            ->line('**Nome:** ' . ($this->lead->name ?? '—'))
            ->line('**Telefone:** ' . ($this->lead->phone ?? '—'));

        if (!empty($this->lead->channel)) {
            $mail->line('**Origem:** ' . $this->lead->channel);
        }

        if (!empty($this->lead->city_of_interest)) {
            $mail->line('**Cidade de interesse:** ' . $this->lead->city_of_interest);
        }

        return $mail
            ->action('Abrir lead no AlphaCRM', $leadUrl)
            ->line('Lembre-se: o SLA de primeiro contato é curto — responda o quanto antes.')
            ->salutation('Alpha Domus · AlphaCRM');
    }
}
