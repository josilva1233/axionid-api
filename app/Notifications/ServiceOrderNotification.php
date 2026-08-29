<?php

namespace App\Notifications;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $serviceOrder;
    protected $message;
    protected $sender;
    protected $type;

    /**
     * 🔥 CORRIGIDO: Aceita ServiceOrderMessage OU stdClass
     * 
     * @param ServiceOrder $serviceOrder
     * @param ServiceOrderMessage|object $message
     * @param User $sender
     * @param string $type
     */
    public function __construct(ServiceOrder $serviceOrder, $message, User $sender, string $type = 'new_message')
    {
        $this->serviceOrder = $serviceOrder;
        $this->message = $message;
        $this->sender = $sender;
        $this->type = $type;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $order = $this->serviceOrder;
        $sender = $this->sender;

        // 🔥 TÍTULO DINÂMICO
        $subject = match($this->type) {
            'new_order' => '📌 Novo Chamado Aberto - ' . $order->protocol,
            'status_change' => '🔄 Status Alterado - ' . $order->protocol,
            'assigned' => '🔧 Chamado Atribuído - ' . $order->protocol,
            default => '💬 Nova Mensagem - ' . $order->protocol,
        };

        // 🔥 MENSAGEM PERSONALIZADA
        $messageText = match($this->type) {
            'new_order' => 'abriu um novo chamado',
            'status_change' => 'alterou o status do chamado para: **' . $this->getStatusLabel($order->status) . '**',
            'assigned' => 'atribuiu o chamado para: **' . ($order->technician?->name ?? 'Técnico') . '**',
            default => 'enviou uma nova mensagem',
        };

        // 🔥 TEXTO DA MENSAGEM (funciona para ambos os tipos)
        $messageContent = $this->getMessageText();

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line("**{$sender->name}** {$messageText} na OS #{$order->protocol}")
            ->line('**Título:** ' . $order->title)
            ->line('**Prioridade:** ' . $this->getPriorityLabel($order->priority))
            ->line('**Status:** ' . $this->getStatusLabel($order->status))
            ->when($this->type !== 'status_change' && $this->type !== 'assigned', function ($mail) use ($messageContent) {
                return $mail->line('**Mensagem:**')
                            ->line('> ' . $messageContent);
            })
            ->when($this->hasAttachment(), function ($mail) {
                return $mail->line('📎 **Anexo:** Disponível para download no sistema.');
            })
            ->action('Ver Chamado', url('/service-orders/' . $order->id))
            ->line('Responda diretamente no sistema para manter o histórico completo.')
            ->line('Atenciosamente,')
            ->line('**Axion ID**');
    }

    public function toDatabase($notifiable)
    {
        return [
            'service_order_id' => $this->serviceOrder->id,
            'message_id' => $this->getMessageId(),
            'sender_id' => $this->sender->id,
            'sender_name' => $this->sender->name,
            'protocol' => $this->serviceOrder->protocol,
            'title' => $this->serviceOrder->title,
            'type' => $this->type,
            'message_preview' => substr($this->getMessageText(), 0, 100),
            'has_attachment' => $this->hasAttachment(),
            'status' => $this->serviceOrder->status,
            'priority' => $this->serviceOrder->priority,
        ];
    }

    // =========================================================
    // 🔥 MÉTODOS AUXILIARES
    // =========================================================

    /**
     * Obtém o texto da mensagem (funciona para ambos os tipos)
     */
    private function getMessageText(): string
    {
        if ($this->message instanceof ServiceOrderMessage) {
            return $this->message->message ?? 'Sem mensagem';
        }
        
        // stdClass ou objeto genérico
        return $this->message->message ?? 'Sem mensagem';
    }

    /**
     * Obtém o ID da mensagem (funciona para ambos os tipos)
     */
    private function getMessageId(): ?int
    {
        if ($this->message instanceof ServiceOrderMessage) {
            return $this->message->id;
        }
        
        return $this->message->id ?? null;
    }

    /**
     * Verifica se tem anexo (funciona para ambos os tipos)
     */
    private function hasAttachment(): bool
    {
        if ($this->message instanceof ServiceOrderMessage) {
            return $this->message->hasAttachment();
        }
        
        return $this->message->has_attachment ?? false;
    }

    private function getStatusLabel($status)
    {
        $statuses = [
            'pending' => '⏳ Pendente',
            'open' => '📂 Em Aberto',
            'in_progress' => '🔧 Em Atendimento',
            'resolved' => '✅ Resolvido',
            'closed' => '🔒 Fechado',
        ];
        return $statuses[$status] ?? $status;
    }

    private function getPriorityLabel($priority)
    {
        $priorities = [
            'low' => '🔵 Baixa',
            'medium' => '🟡 Média',
            'high' => '🟠 Alta',
            'urgent' => '🔴 Urgente',
        ];
        return $priorities[$priority] ?? $priority;
    }
}