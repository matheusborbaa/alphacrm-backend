<?php

namespace App\Notifications;

use App\Models\Commission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sprint 3.8b — Notifica o corretor quando o status da comissão dele muda.
 *
 * Uma única classe cobre todos os eventos (confirmed/approved/paid/partial/
 * cancelled). O $event determina o copy e o canal — email só pra eventos
 * "importantes" pro corretor (approved e paid), pros demais fica só o
 * database notification pra não virar spam.
 *
 * Não usa ShouldQueue: o insert em `notifications` precisa ser síncrono
 * pra o polling do sininho ver no tick seguinte, mesma lógica do
 * LeadAssignedNotification.
 */
class CommissionStatusChangedNotification extends Notification
{
    use Queueable;

    public const EVENT_CONFIRMED = 'confirmed';  // draft → pending
    public const EVENT_APPROVED  = 'approved';   // pending → approved
    public const EVENT_PAID      = 'paid';       // → paid
    public const EVENT_PARTIAL   = 'partial';    // → partial
    public const EVENT_CANCELLED = 'cancelled';  // → cancelled

    /**
     * @param  Commission  $commission  comissão impactada (eager load lead p/ UX)
     * @param  string      $event       um dos EVENT_*
     * @param  string|null $reason      usado quando event = cancelled
     */
    public function __construct(
        public Commission $commission,
        public string $event,
        public ?string $reason = null,
    ) {}

    /**
     * Eventos "importantes" ganham email; demais ficam só no sininho.
     * Corretor pode receber 3-5 notificações por comissão no ciclo todo —
     * mandar email em cada uma é demais.
     */
    public function via($notifiable): array
    {
        $channels = ['database'];

        $emailOnEvents = [self::EVENT_APPROVED, self::EVENT_PAID, self::EVENT_CANCELLED];
        if (in_array($this->event, $emailOnEvents, true) && !empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Payload salvo em `notifications.data`. Frontend consome via /notifications.
     */
    public function toDatabase($notifiable): array
    {
        $leadName = $this->commission->lead?->name ?? ('#' . $this->commission->lead_id);

        return [
            'type'          => 'commission_' . $this->event,
            'title'         => $this->title(),
            'message'       => $this->message($leadName),
            'commission_id' => $this->commission->id,
            'lead_id'       => $this->commission->lead_id,
            'lead_name'     => $leadName,
            'amount'        => (float) $this->commission->commission_value,
            'event'         => $this->event,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $appUrl = rtrim(config('app.frontend_url') ?? config('app.url') ?? '', '/');
        $leadName = $this->commission->lead?->name ?? ('#' . $this->commission->lead_id);
        $commissionsUrl = $appUrl . '/comissoes.php';

        $amount = 'R$ ' . number_format((float) $this->commission->commission_value, 2, ',', '.');

        $mail = (new MailMessage)
            ->subject($this->mailSubject($leadName))
            ->greeting('Olá, ' . ($notifiable->name ?? 'corretor') . '!')
            ->line($this->mailIntro($leadName, $amount));

        if ($this->event === self::EVENT_CANCELLED && $this->reason) {
            $mail->line('**Motivo:** ' . $this->reason);
        }

        return $mail
            ->action('Abrir comissões', $commissionsUrl)
            ->salutation('Alpha Domus · AlphaCRM');
    }

    /* ==================== COPY HELPERS ==================== */

    private function title(): string
    {
        return match ($this->event) {
            self::EVENT_CONFIRMED => 'Venda confirmada',
            self::EVENT_APPROVED  => 'Comissão aprovada',
            self::EVENT_PAID      => 'Comissão paga',
            self::EVENT_PARTIAL   => 'Pagamento parcial registrado',
            self::EVENT_CANCELLED => 'Comissão cancelada',
            default               => 'Comissão atualizada',
        };
    }

    private function message(string $leadName): string
    {
        return match ($this->event) {
            self::EVENT_CONFIRMED => "A venda do lead {$leadName} foi confirmada. Sua comissão está aguardando aprovação.",
            self::EVENT_APPROVED  => "Sua comissão do lead {$leadName} foi aprovada e aguarda pagamento.",
            self::EVENT_PAID      => "Sua comissão do lead {$leadName} foi paga.",
            self::EVENT_PARTIAL   => "Um pagamento parcial da sua comissão do lead {$leadName} foi registrado.",
            self::EVENT_CANCELLED => "A comissão do lead {$leadName} foi cancelada"
                                     . ($this->reason ? ": {$this->reason}" : '.'),
            default               => "A comissão do lead {$leadName} foi atualizada.",
        };
    }

    private function mailSubject(string $leadName): string
    {
        return match ($this->event) {
            self::EVENT_APPROVED  => '✅ Comissão aprovada — ' . $leadName,
            self::EVENT_PAID      => '💰 Comissão paga — ' . $leadName,
            self::EVENT_CANCELLED => '⚠️ Comissão cancelada — ' . $leadName,
            default               => 'Atualização de comissão — ' . $leadName,
        };
    }

    private function mailIntro(string $leadName, string $amount): string
    {
        return match ($this->event) {
            self::EVENT_APPROVED  => "Sua comissão do lead **{$leadName}** foi aprovada no valor de **{$amount}** e agora aguarda pagamento.",
            self::EVENT_PAID      => "Sua comissão do lead **{$leadName}** no valor de **{$amount}** foi paga. Veja o comprovante na tela de comissões.",
            self::EVENT_CANCELLED => "A comissão do lead **{$leadName}** foi cancelada.",
            default               => "A comissão do lead **{$leadName}** foi atualizada.",
        };
    }
}
