<?php

namespace App\Notifications;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $serviceOrder;
    protected $message;
    protected $sender;

    public function __construct(ServiceOrder $serviceOrder, ServiceOrderMessage $message, User $sender)
    {
        $this->serviceOrder = $serviceOrder;
        $this->message = $message;
        $this->sender = $sender;
    }

    public function via($notifiable)
    {
        return ['mail', 'database']; // Adicione 'database' se quiser salvar notificações no banco
    }

    public function toMail($notifiable)
    {
        $order = $this->serviceOrder;
        $sender = $this->sender;

        return (new MailMessage)
            ->subject('💬 Nova Mensagem na OS - ' . $order->protocol)
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line('Você recebeu uma nova mensagem na ordem de serviço **' . $order->protocol . '**')
            ->line('**De:** ' . $sender->name)
            ->line('**Título da OS:** ' . $order->title)
            ->line('**Mensagem:**')
            ->line('> ' . $this->message->message)
            ->when($this->message->hasAttachment(), function ($mail) {
                return $mail->line('📎 **Anexo:** Disponível para download no sistema.');
            })
            ->action('Visualizar Mensagem', url('/service-orders/' . $order->id . '#message-' . $this->message->id))
            ->line('Responda diretamente no sistema para manter o histórico completo.')
            ->line('Atenciosamente,');
    }

    public function toDatabase($notifiable)
    {
        return [
            'service_order_id' => $this->serviceOrder->id,
            'message_id' => $this->message->id,
            'sender_id' => $this->sender->id,
            'sender_name' => $this->sender->name,
            'protocol' => $this->serviceOrder->protocol,
            'title' => $this->serviceOrder->title,
            'message_preview' => substr($this->message->message, 0, 100),
            'has_attachment' => $this->message->hasAttachment(),
            'type' => 'new_message',
        ];
    }
}