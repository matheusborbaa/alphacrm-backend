<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrphanLeadNotification extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead)
    {
    }

    public function via($notifiable): array
    {
        $channels = ['database'];
        if (!empty($notifiable->email)) $channels[] = 'mail';
        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'      => 'lead_orphan',
            'title'     => 'Lead sem corretor',
            'message'   => 'Nenhum corretor disponível recebeu o lead '
                           . ($this->lead->name ?? '#' . $this->lead->id)
                           . '. Atribua manualmente ou aguarde alguém ficar disponível.',
            'lead_id'   => $this->lead->id,
            'lead_name' => $this->lead->name,
            'phone'     => $this->lead->phone,
            'channel'   => $this->lead->channel,
            'sound'     => 'new-lead',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $appUrl  = rtrim(config('app.frontend_url') ?? config('app.url') ?? '', '/');
        $leadUrl = $appUrl . '/lead.html?id=' . $this->lead->id;

        $mail = (new MailMessage)
            ->subject('⚠ Lead sem corretor disponível: ' . ($this->lead->name ?? '#' . $this->lead->id))
            ->greeting('Olá, ' . ($notifiable->name ?? 'admin') . '!')
            ->line('Um novo lead entrou no sistema, mas não havia nenhum corretor com status "disponível" pra receber.')
            ->line('**Nome:** ' . ($this->lead->name ?? '—'))
            ->line('**Telefone:** ' . ($this->lead->phone ?? '—'));

        if (!empty($this->lead->channel)) {
            $mail->line('**Origem:** ' . $this->lead->channel);
        }

        return $mail
            ->action('Abrir lead e atribuir', $leadUrl)
            ->line('Quando um corretor mudar o status pra "disponível", o rodízio vai tentar atribuir esse lead automaticamente.')
            ->salutation('Alpha Domus · AlphaCRM');
    }
}
