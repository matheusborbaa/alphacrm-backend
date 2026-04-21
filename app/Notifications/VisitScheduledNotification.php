<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Broadcasting\PrivateChannel;

class VisitScheduledNotification extends Notification implements ShouldBroadcast
{
    public function __construct(public $lead)
    {
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'title' => 'Nova visita agendada',
            'message' => 'Visita com '.$this->lead->name,
            'lead_id' => $this->lead->id,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return [
            'title' => 'Nova visita agendada',
            'message' => 'Visita com '.$this->lead->name,
            'lead_id' => $this->lead->id,
        ];
    }

    public function broadcastOn()
    {
        return new PrivateChannel('App.Models.User.'.$this->lead->assigned_user_id);
    }
}
